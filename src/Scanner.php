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

    /** C0 controls (U+0000-U+001F). Raw, they are invalid inside any string token. */
    private const string CONTROLS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F";

    /**
     * Bytes that stop the C-level fast scan of a bare token: structural delimiters,
     * the escape introducer, raw C0 controls, and the only four UTF-8 lead bytes that
     * can begin a Unicode whitespace rune (C2, E1, E2, E3 -- see Utf8::isSpaceAbove).
     * Every other byte is token content, so strcspn skips it without leaving C.
     */
    private const string TOKEN_STOP = self::DELIMITERS . '\\' . self::CONTROLS . "\xC2\xE1\xE2\xE3";

    /** Content-scan stops inside a '-quoted string: the delimiter, escapes, controls. */
    private const string STOP_IN_APOSTROPHE = "'" . '\\' . self::CONTROLS;

    /** Content-scan stops inside a "-quoted string. The other quote byte is content. */
    private const string STOP_IN_QUOTE = '"\\' . self::CONTROLS;

    /**
     * Bytes that force the escape-aware bare-token scan: the escape introducer, the C0
     * controls that are not already delimiters, and the UTF-8 lead bytes that can begin
     * a Unicode whitespace rune. A source with none of them cannot contain an escape, a
     * raw control inside a token, or a non-ASCII space -- see {@see $simpleSource}.
     */
    private const string NEEDS_ESCAPE_SCAN = "\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\xC2\xE1\xE2\xE3";

    protected string $src = '';
    protected int $pos = 0;
    protected int $len = 0;

    /**
     * Whether the whole source is free of {@see NEEDS_ESCAPE_SCAN} bytes, decided once
     * in {@see setSource()}. It buys the token scanner the 11-byte DELIMITERS mask
     * instead of the 45-byte escape-aware one: strcspn rebuilds its 256-byte table on
     * every call and bare tokens are short, so mask size dominates that loop.
     */
    protected bool $simpleSource = false;

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

    protected function setSource(string $src): void {
        $this->src = $src;
        $this->len = \strlen($src);
        $this->simpleSource = strcspn($src, self::NEEDS_ESCAPE_SCAN) === $this->len;
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
     * Advance past one bare token. A backslash escape is a single scanner atom, so
     * `a\u0020b` stays one token even though it decodes to a space; the token ends at
     * the first *unescaped* structural delimiter or Unicode whitespace rune. Raw C0
     * controls other than the delimiters tab/LF/CR are invalid string content.
     */
    protected function scanTokenEnd(): void {
        if ($this->simpleSource) {
            $this->pos += strcspn($this->src, self::DELIMITERS, $this->pos);

            return;
        }

        $src = $this->src;
        $len = $this->len;
        while ($this->pos < $len) {
            $this->pos += strcspn($src, self::TOKEN_STOP, $this->pos);
            if ($this->pos >= $len) {
                return;
            }
            $byte = $src[$this->pos];
            if ($byte === '\\') {
                $this->pos = $this->decodeEscape($this->pos)[1];

                continue;
            }
            if ($byte < "\x80") {
                if (strpos(self::DELIMITERS, $byte) !== false) {
                    return;
                }

                // The only other low bytes in TOKEN_STOP are raw C0 controls.
                throw RonException::at('unescaped control character in string', $this->pos);
            }
            [$rune, $size] = Utf8::decodeRune($src, $this->pos, $len);
            if (Utf8::isSpaceAbove($rune)) {
                return;
            }
            $this->pos += $size;
        }
    }

    /**
     * Consume a bare token and return its source bounds. Callers that classify the
     * token (true/false/null/number) must look at these raw source bytes: RON selects
     * the type from the unescaped spelling, so `tr\u0075e` is the string "true".
     *
     * @return array{0: int, 1: int} start and end byte offsets
     */
    protected function tokenSpanBounds(): array {
        $start = $this->pos;
        $this->scanTokenEnd();
        if ($this->pos === $start) {
            throw RonException::at('expected token', $this->pos);
        }

        return [$start, $this->pos];
    }

    /** Consume a bare token and return its raw (still escaped) source text. */
    protected function tokenSpan(): string {
        [$start, $end] = $this->tokenSpanBounds();

        return substr($this->src, $start, $end - $start);
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
            default => $this->parseBareString(),
        };
    }

    /** A bare token taken as a string: escapes decode, no type classification. */
    protected function parseBareString(): string {
        [$start, $end] = $this->tokenSpanBounds();

        return $this->decodeStringSpan($start, $end);
    }

    protected function parseCommaPrefixedToken(): string {
        $start = $this->pos;
        ++$this->pos;
        $this->scanTokenEnd();

        return $this->decodeStringSpan($start, $this->pos);
    }

    /**
     * Decode the JSON escapes in a source span. Spans without a backslash -- the
     * overwhelming majority -- return a plain substring with no per-byte work.
     */
    protected function decodeStringSpan(int $start, int $end): string {
        $raw = substr($this->src, $start, $end - $start);
        $first = strpos($raw, '\\');
        if ($first === false) {
            return $raw;
        }

        $decoded = substr($raw, 0, $first);
        $pos = $start + $first;
        while ($pos < $end) {
            [$rune, $next] = $this->decodeEscape($pos);
            $decoded .= Utf8::encodeRune($rune);
            $pos = $next;
            $offset = strpos($this->src, '\\', $pos);
            if ($offset === false || $offset >= $end) {
                return $decoded . substr($this->src, $pos, $end - $pos);
            }
            $decoded .= substr($this->src, $pos, $offset - $pos);
            $pos = $offset;
        }

        return $decoded;
    }

    /**
     * Decode one JSON escape at $pos (which must hold a backslash).
     *
     * @return array{0: int, 1: int} decoded code point and the offset just past it
     */
    protected function decodeEscape(int $pos): array {
        if ($pos + 1 >= $this->len) {
            throw RonException::at('truncated escape', $pos);
        }

        $escape = $this->src[$pos + 1];
        $simple = match ($escape) {
            '"', '\\', '/' => \ord($escape),
            'b' => 0x08,
            'f' => 0x0C,
            'n' => 0x0A,
            'r' => 0x0D,
            't' => 0x09,
            default => -1,
        };
        if ($simple >= 0) {
            return [$simple, $pos + 2];
        }
        if ($escape !== 'u') {
            throw RonException::at('unknown escape', $pos);
        }
        if ($pos + 6 > $this->len) {
            throw RonException::at('truncated unicode escape', $pos);
        }

        $value = $this->decodeHex4($pos + 2);
        $next = $pos + 6;
        if ($value >= 0xD800 && $value <= 0xDBFF) {
            if ($next + 6 > $this->len || $this->src[$next] !== '\\' || $this->src[$next + 1] !== 'u') {
                throw RonException::at('unpaired high surrogate', $pos);
            }
            $low = $this->decodeHex4($next + 2);
            if ($low < 0xDC00 || $low > 0xDFFF) {
                throw RonException::at('unpaired high surrogate', $pos);
            }

            return [0x10000 + (($value - 0xD800) << 10) + ($low - 0xDC00), $next + 6];
        }
        if ($value >= 0xDC00 && $value <= 0xDFFF) {
            throw RonException::at('unpaired low surrogate', $pos);
        }

        return [$value, $next];
    }

    /**
     * Parse an N-quoted string. Quoting only frames the token: the content uses the
     * same JSON escape decoder as every other string form.
     *
     * Framing is delimiter-aware. Only an unescaped run of the *opening* quote byte
     * that is at least N long closes the string; shorter runs and every occurrence of
     * the other quote byte are ordinary content (which is why the other quote is
     * absent from the scan-stop set and strcspn walks straight past it).
     */
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
            // Apostrophe-only compatibility form: a lone run stands for apostrophes.
            // A double-quote run never takes it.
            if ($quote === "'" && $count >= 5 && ($count - 2) % 3 === 0) {
                $this->pos += $count;

                return str_repeat($quote, intdiv($count - 2, 3));
            }
        }

        $stop = $quote === "'" ? self::STOP_IN_APOSTROPHE : self::STOP_IN_QUOTE;
        $this->pos += $count;
        $start = $this->pos;
        while ($this->pos < $len) {
            $this->pos += strcspn($src, $stop, $this->pos);
            if ($this->pos >= $len) {
                break;
            }
            $byte = $src[$this->pos];
            if ($byte === '\\') {
                $this->pos = $this->decodeEscape($this->pos)[1];

                continue;
            }
            if ($byte === $quote) {
                $run = strspn($src, $quote, $this->pos);
                if ($run >= $count) {
                    $end = $this->pos;
                    $this->pos += $count;

                    return $this->decodeStringSpan($start, $end);
                }
                $this->pos += $run;

                continue;
            }

            throw RonException::at('unescaped control character in string', $this->pos);
        }

        $this->pos = $len;

        throw RonException::at('unterminated string', $this->pos);
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

    private function decodeHex4(int $start): int {
        $hex = substr($this->src, $start, 4);
        // ctype_xdigit rejects the "0x"/whitespace forms hexdec() would otherwise accept.
        if (!ctype_xdigit($hex)) {
            throw RonException::at('non-hex unicode escape', $start);
        }

        return (int) hexdec($hex);
    }

    private static function isDelimiterByte(string $byte): bool {
        return strpos(self::DELIMITERS, $byte) !== false;
    }
}
