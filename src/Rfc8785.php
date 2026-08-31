<?php

declare(strict_types=1);

namespace Mbolli\Ron;

use Mbolli\Ron\Value\MultilineList;
use Mbolli\Ron\Value\RonNumber;
use Mbolli\Ron\Value\RonObject;

/**
 * RFC 8785 JSON Canonicalization Scheme (port of ron-go's rfc8785.go).
 *
 * This is a separate byte contract from RON's compact JSON: numbers are normalized
 * to ECMAScript double serialization, object keys are sorted by UTF-16 code units,
 * and the I-JSON value constraints of RFC 7493 apply -- duplicate decoded member
 * names, lone surrogates, invalid UTF-8 and Unicode noncharacters are rejected.
 *
 * A finite source number may *round* on its way to IEEE 754 double precision
 * (9007199254740993 canonicalizes to 9007199254740992); only a conversion that
 * produces a non-finite value is an error.
 *
 * Numbers are carried as PHP floats in the value model: null | bool | string |
 * float | list<mixed> | RonObject.
 */
final class Rfc8785 {
    private const string WS = "\x20\x09\x0A\x0D";

    private string $src;
    private int $pos = 0;
    private int $len;
    private int $maxDepth;

    private function __construct(string $src, int $maxDepth) {
        $this->src = $src;
        $this->len = \strlen($src);
        $this->maxDepth = $maxDepth;
    }

    public static function canonicalize(string $src, int $maxDepth = 512): string {
        return self::write(self::parse($src, $maxDepth));
    }

    /**
     * The same validating parse as {@see canonicalize()}, but handed back in the
     * *parser* value model with each number replaced by its ECMAScript serialization.
     *
     * Canonical RON renders straight from this, so it costs one parse rather than a
     * canonical-JSON serialize plus a second parse.
     */
    public static function canonicalModel(string $src, int $maxDepth = 512): mixed {
        return self::toNumberTextModel(self::parse($src, $maxDepth));
    }

    /**
     * RFC 8785 canonical JSON for a node of the *parser* value model (the one
     * JsonParser produces, where numbers are {@see RonNumber} source text).
     *
     * The set vocabulary uses these bytes as the identity of a `#set` element, so
     * `{b 2 a 1}` and `{a 1 b 2}` compare equal.
     */
    public static function canonicalizeValue(mixed $value): string {
        return self::write(self::toDoubleModel($value));
    }

    private static function parse(string $src, int $maxDepth): mixed {
        self::scanSurrogates($src);
        $parser = new self($src, $maxDepth);
        $value = $parser->parseValue(0);
        $parser->skipWs();
        if ($parser->pos !== $parser->len) {
            throw RonException::at('unexpected trailing JSON', $parser->pos);
        }

        return $value;
    }

    /** Float-numbered model -> the RonNumber-carrying model the renderers consume. */
    private static function toNumberTextModel(mixed $value): mixed {
        if (\is_float($value)) {
            return new RonNumber(self::number($value));
        }
        if (\is_array($value)) {
            return array_map(self::toNumberTextModel(...), $value);
        }
        if ($value instanceof RonObject) {
            $object = new RonObject();
            foreach ($value->members() as [$key, $member]) {
                $object->set($key, self::toNumberTextModel($member));
            }

            return $object;
        }

        return $value;
    }

    /** Reject lone surrogates in \uXXXX escapes (RFC 8785 Sections 3.1, 3.2.2.2). */
    private static function scanSurrogates(string $src): void {
        $len = \strlen($src);
        for ($i = 0; $i < $len; ++$i) {
            if ($src[$i] !== '"') {
                continue;
            }
            ++$i;
            while ($i < $len && $src[$i] !== '"') {
                if ($src[$i] !== '\\') {
                    ++$i;

                    continue;
                }
                ++$i;
                if ($i === $len) {
                    break;
                }
                if ($src[$i] !== 'u') {
                    ++$i;

                    continue;
                }
                $code = self::hex4($src, $i + 1, $len);
                if ($code === null) {
                    break;
                }
                if ($code >= 0xD800 && $code <= 0xDBFF) {
                    if ($i + 11 >= $len || $src[$i + 5] !== '\\' || $src[$i + 6] !== 'u') {
                        throw new RonException('ron: invalid lone surrogate');
                    }
                    $low = self::hex4($src, $i + 7, $len);
                    if ($low === null || $low < 0xDC00 || $low > 0xDFFF) {
                        throw new RonException('ron: invalid lone surrogate');
                    }
                    $i += 11;
                } elseif ($code >= 0xDC00 && $code <= 0xDFFF) {
                    throw new RonException('ron: invalid lone surrogate');
                } else {
                    $i += 5;
                }
            }
        }
    }

    private static function hex4(string $src, int $at, int $len): ?int {
        if ($at + 4 > $len) {
            return null;
        }
        $hex = substr($src, $at, 4);
        if (preg_match('/\A[0-9a-fA-F]{4}\z/', $hex) !== 1) {
            return null;
        }

        return (int) hexdec($hex);
    }

    private function parseValue(int $depth): mixed {
        $this->skipWs();
        if ($this->pos >= $this->len) {
            throw RonException::at('expected JSON value', $this->pos);
        }
        $c = $this->src[$this->pos];

        return match (true) {
            $c === '{' => $this->parseObject($depth),
            $c === '[' => $this->parseArray($depth),
            $c === '"' => $this->parseString(),
            $c === 't' => $this->parseLiteral('true', true),
            $c === 'f' => $this->parseLiteral('false', false),
            $c === 'n' => $this->parseLiteral('null', null),
            $c === '-' || ($c >= '0' && $c <= '9') => $this->parseNumber(),
            default => throw RonException::at('unexpected JSON token', $this->pos),
        };
    }

    private function parseObject(int $depth): RonObject {
        if ($depth >= $this->maxDepth) {
            throw RonException::at('maximum nesting depth exceeded', $this->pos);
        }
        ++$this->pos;
        $object = new RonObject();
        $seen = [];
        $this->skipWs();
        if ($this->pos < $this->len && $this->src[$this->pos] === '}') {
            ++$this->pos;

            return $object;
        }

        while (true) {
            $this->skipWs();
            if ($this->pos >= $this->len || $this->src[$this->pos] !== '"') {
                throw RonException::at('expected JSON object key', $this->pos);
            }
            $key = $this->parseString();
            if (isset($seen[$key])) {
                throw new RonException('ron: duplicate JSON object key');
            }
            $seen[$key] = true;

            $this->skipWs();
            if ($this->pos >= $this->len || $this->src[$this->pos] !== ':') {
                throw RonException::at('expected :', $this->pos);
            }
            ++$this->pos;
            $object->set($key, $this->parseValue($depth + 1));

            $this->skipWs();
            if ($this->pos >= $this->len) {
                throw RonException::at('expected , or }', $this->pos);
            }
            $c = $this->src[$this->pos];
            if ($c === ',') {
                ++$this->pos;

                continue;
            }
            if ($c === '}') {
                ++$this->pos;

                return $object;
            }

            throw RonException::at('expected , or }', $this->pos);
        }
    }

    /** @return list<mixed> */
    private function parseArray(int $depth): array {
        if ($depth >= $this->maxDepth) {
            throw RonException::at('maximum nesting depth exceeded', $this->pos);
        }
        ++$this->pos;
        $array = [];
        $this->skipWs();
        if ($this->pos < $this->len && $this->src[$this->pos] === ']') {
            ++$this->pos;

            return $array;
        }

        while (true) {
            $array[] = $this->parseValue($depth + 1);
            $this->skipWs();
            if ($this->pos >= $this->len) {
                throw RonException::at('expected , or ]', $this->pos);
            }
            $c = $this->src[$this->pos];
            if ($c === ',') {
                ++$this->pos;

                continue;
            }
            if ($c === ']') {
                ++$this->pos;

                return $array;
            }

            throw RonException::at('expected , or ]', $this->pos);
        }
    }

    private function parseLiteral(string $literal, mixed $value): mixed {
        if (substr_compare($this->src, $literal, $this->pos, \strlen($literal)) !== 0) {
            throw RonException::at('invalid JSON literal', $this->pos);
        }
        $this->pos += \strlen($literal);

        return $value;
    }

    private function parseNumber(): float {
        if (
            preg_match(
                '/-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/A',
                $this->src,
                $m,
                0,
                $this->pos,
            ) !== 1
        ) {
            throw RonException::at('invalid JSON number', $this->pos);
        }
        $text = $m[0];
        $this->pos += \strlen($text);

        $float = (float) $text;
        // RFC 8785 Section 3.1: only a conversion that is not finite is rejected.
        // Rounding a finite value (9007199254740993 -> ...992) is expected.
        if (!is_finite($float)) {
            throw new RonException('ron: JSON number is not a finite IEEE 754 double');
        }

        return $float;
    }

    private function parseString(): string {
        $src = $this->src;
        $len = $this->len;
        ++$this->pos;
        $start = $this->pos;
        $result = '';

        while ($this->pos < $len) {
            $c = $src[$this->pos];
            if ($c === '"') {
                $result .= substr($src, $start, $this->pos - $start);
                ++$this->pos;
                self::validateIJsonString($result);

                return $result;
            }
            if ($c === '\\') {
                $result .= substr($src, $start, $this->pos - $start);
                ++$this->pos;
                if ($this->pos >= $len) {
                    break;
                }
                $result .= $this->parseEscape();
                $start = $this->pos;

                continue;
            }
            if (\ord($c) < 0x20) {
                throw RonException::at('control character in JSON string', $this->pos);
            }
            ++$this->pos;
        }

        throw RonException::at('unterminated JSON string', $this->pos);
    }

    private function parseEscape(): string {
        $e = $this->src[$this->pos];

        switch ($e) {
            case '"':
            case '\\':
            case '/':
                $this->pos++;

                return $e;

            case 'b':
                $this->pos++;

                return "\x08";

            case 'f':
                $this->pos++;

                return "\x0C";

            case 'n':
                $this->pos++;

                return "\n";

            case 'r':
                $this->pos++;

                return "\r";

            case 't':
                $this->pos++;

                return "\t";

            case 'u':
                $code = self::hex4($this->src, $this->pos + 1, $this->len);
                if ($code === null) {
                    throw RonException::at('invalid \\u escape', $this->pos);
                }
                $this->pos += 5;
                if ($code >= 0xD800 && $code <= 0xDBFF) {
                    // Surrogate pairing already validated by scanSurrogates().
                    $low = (int) self::hex4($this->src, $this->pos + 2, $this->len);
                    $this->pos += 6;

                    return Utf8::encodeRune(0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00));
                }

                return Utf8::encodeRune($code);

            default:
                throw RonException::at('invalid JSON escape', $this->pos);
        }
    }

    /**
     * I-JSON string content check (RFC 7493 Section 2.1), applied to decoded bytes so
     * it catches a noncharacter written directly *or* via a \uXXXX escape.
     */
    private static function validateIJsonString(string $value): void {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new RonException('ron: invalid UTF-8 in JSON string');
        }
        // Every noncharacter's UTF-8 encoding starts with EF (U+FDD0-FDEF, U+FFFE,
        // U+FFFF) or F0-F4 (the plane-end pairs), so nothing else can match and the
        // per-rune walk below is skipped for ordinary text.
        if (strcspn($value, "\xEF\xF0\xF1\xF2\xF3\xF4") === \strlen($value)) {
            return;
        }
        $len = \strlen($value);
        for ($i = 0; $i < $len;) {
            [$rune, $size] = Utf8::decodeRune($value, $i, $len);
            // (rune & 0xFFFE) === 0xFFFE matches U+FFFE/U+FFFF in every plane.
            if (($rune >= 0xFDD0 && $rune <= 0xFDEF) || ($rune & 0xFFFE) === 0xFFFE) {
                throw new RonException('ron: Unicode noncharacter in JSON string');
            }
            $i += $size;
        }
    }

    /** Value model -> the float-numbered model {@see write()} consumes. */
    private static function toDoubleModel(mixed $value): mixed {
        if ($value instanceof RonNumber) {
            $float = (float) $value->text;
            if (!is_finite($float)) {
                throw new RonException('ron: JSON number is not a finite IEEE 754 double');
            }

            return $float;
        }
        if ($value instanceof MultilineList) {
            $value = $value->items;
        }
        if (\is_array($value)) {
            return array_map(self::toDoubleModel(...), $value);
        }
        if ($value instanceof RonObject) {
            $object = new RonObject();
            foreach ($value->members() as [$key, $member]) {
                $object->set($key, self::toDoubleModel($member));
            }

            return $object;
        }

        return $value;
    }

    // Recurses over the parsed tree, whose depth parseValue() already bounded to
    // maxDepth, so no separate depth guard is needed here.
    private static function write(mixed $value): string {
        if ($value === null) {
            return 'null';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (\is_string($value)) {
            return JsonString::quote($value);
        }
        if (\is_float($value)) {
            return self::number($value);
        }
        if (\is_array($value)) {
            $parts = array_map(self::write(...), $value);

            return '[' . implode(',', $parts) . ']';
        }
        if (!$value instanceof RonObject) {
            throw new RonException('ron: unsupported value type');
        }

        $keys = $value->keys;
        $values = $value->values;
        Canonical::sortKeyedValues($keys, $values);
        $count = \count($keys);
        $out = '{';
        for ($i = 0; $i < $count; ++$i) {
            if ($i > 0) {
                $out .= ',';
            }
            $out .= JsonString::quote($keys[$i]) . ':' . self::write($values[$i]);
        }

        return $out . '}';
    }

    /** ECMAScript Number serialization (RFC 8785 Section 3.2.2.3 / Appendix B). */
    private static function number(float $value): string {
        if (!is_finite($value)) {
            throw new RonException('ron: non-finite JSON number');
        }
        if ($value === 0.0) {
            return '0';
        }
        $sign = '';
        if ($value < 0) {
            $sign = '-';
            $value = -$value;
        }

        $s = \sprintf('%.17e', $value);
        for ($p = 0; $p < 17; ++$p) {
            $candidate = \sprintf('%.' . $p . 'e', $value);
            if ((float) $candidate === $value) {
                $s = $candidate;

                break;
            }
        }
        [$mant, $exp] = explode('e', $s);
        $digits = str_replace('.', '', $mant);
        $decimalExp = (int) $exp + 1;
        $count = \strlen($digits);

        if ($decimalExp > 0 && $decimalExp <= 21) {
            if ($count <= $decimalExp) {
                return $sign . $digits . str_repeat('0', $decimalExp - $count);
            }

            return $sign . substr($digits, 0, $decimalExp) . '.' . substr($digits, $decimalExp);
        }
        if ($decimalExp > -6 && $decimalExp <= 0) {
            return $sign . '0.' . str_repeat('0', -$decimalExp) . $digits;
        }

        $body = $digits[0];
        if ($count > 1) {
            $body .= '.' . substr($digits, 1);
        }
        $e = $decimalExp - 1;

        return $sign . $body . 'e' . ($e >= 0 ? '+' : '') . $e;
    }

    private function skipWs(): void {
        $this->pos += strspn($this->src, self::WS, $this->pos);
    }
}
