<?php

declare(strict_types=1);

namespace Mbolli\Ron;

use Mbolli\Ron\Value\RonNumber;
use Mbolli\Ron\Value\RonObject;

/**
 * JSON -> value-model parser (port of ron-go's decodeJSON).
 *
 * Builds the same value model the RON renderer consumes:
 *   null | bool | string | RonNumber | list<mixed> | RonObject
 *
 * Number source text is preserved (RonNumber), objects keep insertion order, and
 * an optional typed-value hook is applied bottom-up at every node. PHP's native
 * json_decode is unsuitable here: it discards number text, coerces numeric string
 * keys to integers, and reorders duplicate keys.
 *
 * @phpstan-type Mapper callable(list<string|int>, mixed): array{0: mixed, 1: bool}
 */
final class JsonParser {
    private const string WS = "\x20\x09\x0A\x0D";

    /** Bytes that end a JSON string scan: quote, backslash, and control bytes. */
    private const string STRING_STOP = "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F"
        . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F";

    private string $src;
    private int $pos = 0;
    private int $len;
    private int $maxDepth;

    /** @var null|(\Closure(list<int|string>, mixed): array{0: mixed, 1: bool}) */
    private ?\Closure $mapper;

    /** @param null|Mapper $mapper */
    public function __construct(string $src, ?callable $mapper = null, int $maxDepth = 512) {
        $this->src = $src;
        $this->len = \strlen($src);
        $this->mapper = $mapper === null ? null : $mapper(...);
        $this->maxDepth = $maxDepth;
    }

    public function parse(): mixed {
        $value = $this->parseValue([], 0);
        $this->skipWs();
        if ($this->pos !== $this->len) {
            throw RonException::at('unexpected trailing JSON', $this->pos);
        }

        return $value;
    }

    /** @param list<int|string> $path */
    private function parseValue(array $path, int $depth): mixed {
        $this->skipWs();
        if ($this->pos >= $this->len) {
            throw RonException::at('expected JSON value', $this->pos);
        }

        $c = $this->src[$this->pos];
        $value = match (true) {
            $c === '{' => $this->parseObject($path, $depth),
            $c === '[' => $this->parseArray($path, $depth),
            $c === '"' => $this->parseString(),
            $c === 't' => $this->parseLiteral('true', true),
            $c === 'f' => $this->parseLiteral('false', false),
            $c === 'n' => $this->parseLiteral('null', null),
            $c === '-' || ($c >= '0' && $c <= '9') => $this->parseNumber(),
            default => throw RonException::at('unexpected JSON token', $this->pos),
        };

        return $this->mapper === null ? $value : $this->mapValue($path, $value);
    }

    /** @param list<int|string> $path */
    private function parseObject(array $path, int $depth): RonObject {
        if ($depth >= $this->maxDepth) {
            throw RonException::at('maximum nesting depth exceeded', $this->pos);
        }
        ++$this->pos;
        $object = new RonObject();
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

            $this->skipWs();
            if ($this->pos >= $this->len || $this->src[$this->pos] !== ':') {
                throw RonException::at('expected :', $this->pos);
            }
            ++$this->pos;

            // Path tracking is only needed to feed the mapper; skip the array copy otherwise.
            $object->set($key, $this->parseValue(
                $this->mapper === null ? $path : [...$path, $key],
                $depth + 1,
            ));

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

    /**
     * @param list<int|string> $path
     *
     * @return list<mixed>
     */
    private function parseArray(array $path, int $depth): array {
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
            $array[] = $this->parseValue(
                $this->mapper === null ? $path : [...$path, \count($array)],
                $depth + 1,
            );

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

    private function parseNumber(): RonNumber {
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
        $token = $m[0];
        $this->pos += \strlen($token);

        return new RonNumber($token);
    }

    private function parseString(): string {
        $src = $this->src;
        $len = $this->len;
        ++$this->pos; // opening quote
        $start = $this->pos;

        // Fast path: scan to the next quote/backslash/control byte in C. The common
        // case (no escapes) is a single strcspn + substr.
        $pos = $start + strcspn($src, self::STRING_STOP, $start);
        if ($pos < $len && $src[$pos] === '"') {
            $this->pos = $pos + 1;

            return substr($src, $start, $pos - $start);
        }

        $result = '';
        $this->pos = $pos;
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
                $this->pos += strcspn($src, self::STRING_STOP, $this->pos);

                continue;
            }

            // control byte < 0x20
            throw RonException::at('control character in JSON string', $this->pos);
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
                return $this->parseUnicodeEscape();

            default:
                throw RonException::at('invalid JSON escape', $this->pos);
        }
    }

    private function parseUnicodeEscape(): string {
        $cp = $this->readHex4($this->pos + 1);
        $this->pos += 5; // 'u' + 4 hex digits

        if ($cp >= 0xD800 && $cp <= 0xDBFF) {
            if (
                $this->pos + 1 < $this->len
                && $this->src[$this->pos] === '\\'
                && $this->src[$this->pos + 1] === 'u'
            ) {
                $low = $this->readHex4($this->pos + 2);
                if ($low >= 0xDC00 && $low <= 0xDFFF) {
                    $this->pos += 6;

                    return self::utf8Encode(0x10000 + (($cp - 0xD800) << 10) + ($low - 0xDC00));
                }
            }

            return self::utf8Encode(0xFFFD); // lone high surrogate
        }
        if ($cp >= 0xDC00 && $cp <= 0xDFFF) {
            return self::utf8Encode(0xFFFD); // lone low surrogate
        }

        return self::utf8Encode($cp);
    }

    private function readHex4(int $at): int {
        if ($at + 4 > $this->len) {
            throw RonException::at('invalid \\u escape', $at);
        }
        $hex = substr($this->src, $at, 4);
        if (preg_match('/\A[0-9a-fA-F]{4}\z/', $hex) !== 1) {
            throw RonException::at('invalid \\u escape', $at);
        }

        return (int) hexdec($hex);
    }

    private static function utf8Encode(int $cp): string {
        if ($cp < 0x80) {
            return \chr($cp);
        }
        if ($cp < 0x800) {
            return \chr(0xC0 | ($cp >> 6)) . \chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return \chr(0xE0 | ($cp >> 12))
                . \chr(0x80 | (($cp >> 6) & 0x3F))
                . \chr(0x80 | ($cp & 0x3F));
        }

        return \chr(0xF0 | ($cp >> 18))
            . \chr(0x80 | (($cp >> 12) & 0x3F))
            . \chr(0x80 | (($cp >> 6) & 0x3F))
            . \chr(0x80 | ($cp & 0x3F));
    }

    /** @param list<int|string> $path */
    private function mapValue(array $path, mixed $value): mixed {
        if ($this->mapper === null) {
            return $value;
        }
        [$replacement, $replaced] = ($this->mapper)($path, $value);

        return $replaced ? Encoder::toModel($replacement) : $value;
    }

    private function skipWs(): void {
        // Fast path: avoid strspn's per-call mask build when not on whitespace
        // (compact JSON has none between tokens).
        if ($this->pos >= $this->len) {
            return;
        }
        $c = $this->src[$this->pos];
        if ($c === ' ' || $c === "\n" || $c === "\r" || $c === "\t") {
            $this->pos += strspn($this->src, self::WS, $this->pos);
        }
    }
}
