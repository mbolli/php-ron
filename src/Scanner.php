<?php

declare(strict_types=1);

namespace Mbolli\Ron;

/**
 * Low-level RON scanning primitives shared by the RON-consuming converters.
 *
 * Ported from ron-go's parser (parse.go). State is held in protected fields so the
 * recursive-descent methods avoid re-passing position by reference on every call.
 */
abstract class Scanner {
    /** ASCII structural delimiters: { } [ ] " ' , space tab LF CR. */
    protected const string DELIMITERS = "{}[]\"',\x20\x09\x0A\x0D";

    protected string $src = '';
    protected int $pos = 0;
    protected int $len = 0;

    /**
     * Matches the JSON number grammar: -? (0 | [1-9][0-9]*) (.[0-9]+)? ([eE][+-]?[0-9]+)?
     *
     * Hand-rolled rather than a regex: number tokens are short, so this beats PCRE
     * setup cost on the hot path. Mirrors Go's looksLikeNumberBytes.
     */
    public static function looksLikeNumber(string $token): bool {
        $n = \strlen($token);
        if ($n === 0) {
            return false;
        }
        $i = 0;
        if ($token[0] === '-') {
            $i = 1;
            if ($i === $n) {
                return false;
            }
        }
        if ($token[$i] === '0') {
            ++$i;
        } elseif ($token[$i] >= '1' && $token[$i] <= '9') {
            do {
                ++$i;
            } while ($i < $n && $token[$i] >= '0' && $token[$i] <= '9');
        } else {
            return false;
        }
        if ($i < $n && $token[$i] === '.') {
            ++$i;
            if ($i === $n || $token[$i] < '0' || $token[$i] > '9') {
                return false;
            }
            do {
                ++$i;
            } while ($i < $n && $token[$i] >= '0' && $token[$i] <= '9');
        }
        if ($i < $n && ($token[$i] === 'e' || $token[$i] === 'E')) {
            ++$i;
            if ($i < $n && ($token[$i] === '+' || $token[$i] === '-')) {
                ++$i;
            }
            if ($i === $n || $token[$i] < '0' || $token[$i] > '9') {
                return false;
            }
            do {
                ++$i;
            } while ($i < $n && $token[$i] >= '0' && $token[$i] <= '9');
        }

        return $i === $n;
    }

    /** Top-level space includes commas and Unicode whitespace. */
    protected function skipSpace(): void {
        // Manual short-run loop: separator runs are usually a single byte in compact
        // output, so this avoids strspn rebuilding a 256-byte mask on every call.
        $src = $this->src;
        $len = $this->len;
        $pos = $this->pos;
        while ($pos < $len) {
            $c = $src[$pos];
            if ($c === ' ' || $c === ',' || $c === "\t" || $c === "\n" || $c === "\r") {
                ++$pos;

                continue;
            }
            if ($c < "\x80") {
                break;
            }
            [$rune, $size] = Utf8::decodeRune($src, $pos, $len);
            if (!Utf8::isSpaceAbove($rune)) {
                break;
            }
            $pos += $size;
        }
        $this->pos = $pos;
    }

    /** Inner whitespace excludes commas but includes Unicode whitespace. */
    protected function skipWhitespace(): void {
        $src = $this->src;
        $len = $this->len;
        $pos = $this->pos;
        while ($pos < $len) {
            $c = $src[$pos];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                ++$pos;

                continue;
            }
            if ($c < "\x80") {
                break;
            }
            [$rune, $size] = Utf8::decodeRune($src, $pos, $len);
            if (!Utf8::isSpaceAbove($rune)) {
                break;
            }
            $pos += $size;
        }
        $this->pos = $pos;
    }

    /** After a value: whitespace followed by any number of optional commas. */
    protected function skipSeparators(): void {
        while (true) {
            $this->skipWhitespace();
            if ($this->pos >= $this->len || $this->src[$this->pos] !== ',') {
                return;
            }
            ++$this->pos;
        }
    }

    /**
     * Consume a bare token up to the next ASCII delimiter. Non-ASCII bytes
     * (including multibyte whitespace) are token content, matching Go.
     */
    protected function tokenSpan(): string {
        $start = $this->pos;
        $this->pos += strcspn($this->src, self::DELIMITERS, $this->pos);
        if ($this->pos === $start) {
            throw RonException::at('expected token', $this->pos);
        }

        return substr($this->src, $start, $this->pos - $start);
    }

    protected function parseKey(): string {
        if ($this->pos >= $this->len) {
            throw RonException::at('expected object key', $this->pos);
        }

        return match ($this->src[$this->pos]) {
            ',' => $this->parseCommaPrefixedToken(),
            "'" => $this->parseApostropheValue(),
            '"' => $this->parseQuotedString(),
            '{', '}', '[', ']' => throw RonException::at('expected object key', $this->pos),
            default => $this->tokenSpan(),
        };
    }

    protected function parseCommaPrefixedToken(): string {
        $start = $this->pos;
        ++$this->pos;
        $this->pos += strcspn($this->src, self::DELIMITERS, $this->pos);

        return substr($this->src, $start, $this->pos - $start);
    }

    protected function parseQuotedString(): string {
        $src = $this->src;
        $len = $this->len;
        $quote = $src[$this->pos];

        $count = strspn($src, $quote, $this->pos);
        $afterRun = $this->pos + $count;
        if ($afterRun === $len || self::isDelimiterByte($src[$afterRun])) {
            if ($count % 2 === 0) {
                $this->pos += $count;

                return '';
            }
            if ($count >= 5 && ($count - 2) % 3 === 0) {
                $this->pos += $count;

                return str_repeat($quote, intdiv($count - 2, 3));
            }
        }

        $this->pos += $count;
        $start = $this->pos;
        while (true) {
            if ($this->pos >= $len) {
                throw RonException::at('unterminated string', $this->pos);
            }
            $next = strpos($src, $quote, $this->pos);
            if ($next === false) {
                $this->pos = $len;

                throw RonException::at('unterminated string', $this->pos);
            }
            $this->pos = $next;
            $run = strspn($src, $quote, $this->pos);
            if ($run >= $count) {
                $value = substr($src, $start, $this->pos - $start);
                $this->pos += $count;

                return $value;
            }
            $this->pos += $run;
        }
    }

    protected function parseApostropheValue(): string {
        $src = $this->src;
        $len = $this->len;
        $pos = $this->pos;

        $apostropheIsToken = false;
        if ($pos + 1 === $len) {
            $apostropheIsToken = true;
        } elseif ($src[$pos + 1] === ' ' || $src[$pos + 1] === "\t" || $src[$pos + 1] === "\n" || $src[$pos + 1] === "\r") {
            $apostropheIsToken = true;
            for ($p = $pos + 2; $p < $len; ++$p) {
                $c = $src[$p];
                if ($c === "'") {
                    $apostropheIsToken = false;

                    break;
                }
                if ($c === '{' || $c === '}' || $c === '[' || $c === ']') {
                    break;
                }
            }
        }
        if ($apostropheIsToken) {
            ++$this->pos;

            return "'";
        }

        $start = $this->pos;

        try {
            return $this->parseQuotedString();
        } catch (RonException $e) {
            $this->pos = $start;
            if ($this->pos + 1 === $len || self::isDelimiterByte($src[$this->pos + 1])) {
                ++$this->pos;

                return "'";
            }

            throw $e;
        }
    }

    private static function isDelimiterByte(string $byte): bool {
        return strpos(self::DELIMITERS, $byte) !== false;
    }
}
