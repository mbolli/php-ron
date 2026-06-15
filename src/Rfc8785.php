<?php

declare(strict_types=1);

namespace Mbolli\Ron;

use Mbolli\Ron\Value\RonObject;

/**
 * RFC 8785 JSON Canonicalization Scheme (port of ron-go's rfc8785.go).
 *
 * This is a separate byte contract from RON's compact JSON: numbers are normalized
 * to ECMAScript double serialization, object keys are sorted by UTF-16 code units,
 * duplicate keys and lone surrogates are rejected, and integers must be exactly
 * representable as IEEE 754 doubles.
 *
 * Numbers are carried as PHP floats in the value model: null | bool | string |
 * float | list<mixed> | RonObject.
 */
final class Rfc8785 {
    private const string WS = "\x20\x09\x0A\x0D";

    private string $src;
    private int $pos = 0;
    private int $len;

    private function __construct(string $src) {
        $this->src = $src;
        $this->len = \strlen($src);
    }

    public static function canonicalize(string $src): string {
        self::scanSurrogates($src);
        $parser = new self($src);
        $value = $parser->parseValue();
        $parser->skipWs();
        if ($parser->pos !== $parser->len) {
            throw RonException::at('unexpected trailing JSON', $parser->pos);
        }

        return self::write($value);
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

    private function parseValue(): mixed {
        $this->skipWs();
        if ($this->pos >= $this->len) {
            throw RonException::at('expected JSON value', $this->pos);
        }
        $c = $this->src[$this->pos];

        return match (true) {
            $c === '{' => $this->parseObject(),
            $c === '[' => $this->parseArray(),
            $c === '"' => $this->parseString(),
            $c === 't' => $this->parseLiteral('true', true),
            $c === 'f' => $this->parseLiteral('false', false),
            $c === 'n' => $this->parseLiteral('null', null),
            $c === '-' || ($c >= '0' && $c <= '9') => $this->parseNumber(),
            default => throw RonException::at('unexpected JSON token', $this->pos),
        };
    }

    private function parseObject(): RonObject {
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
            $object->set($key, $this->parseValue());

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
    private function parseArray(): array {
        ++$this->pos;
        $array = [];
        $this->skipWs();
        if ($this->pos < $this->len && $this->src[$this->pos] === ']') {
            ++$this->pos;

            return $array;
        }

        while (true) {
            $array[] = $this->parseValue();
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
        if (!is_finite($float)) {
            throw new RonException('ron: invalid JSON number');
        }
        // Integers must be exactly representable as an IEEE 754 double.
        if (strpbrk($text, '.eE') === false && \sprintf('%.0f', $float) !== $text) {
            throw new RonException('ron: JSON integer is not exactly representable as float64');
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

                    return self::utf8Encode(0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00));
                }

                return self::utf8Encode($code);

            default:
                throw RonException::at('invalid JSON escape', $this->pos);
        }
    }

    private static function utf8Encode(int $cp): string {
        if ($cp < 0x80) {
            return \chr($cp);
        }
        if ($cp < 0x800) {
            return \chr(0xC0 | ($cp >> 6)) . \chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return \chr(0xE0 | ($cp >> 12)) . \chr(0x80 | (($cp >> 6) & 0x3F)) . \chr(0x80 | ($cp & 0x3F));
        }

        return \chr(0xF0 | ($cp >> 18))
            . \chr(0x80 | (($cp >> 12) & 0x3F))
            . \chr(0x80 | (($cp >> 6) & 0x3F))
            . \chr(0x80 | ($cp & 0x3F));
    }

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
