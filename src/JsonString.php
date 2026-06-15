<?php

declare(strict_types=1);

namespace Mbolli\Ron;

/**
 * JSON string-literal escaping, matching ron-go's writeJSONQuoted.
 *
 * Escapes only `"`, `\` and control bytes (< 0x20); valid non-ASCII passes through
 * raw (no \u escaping), invalid UTF-8 bytes become U+FFFD. HTML-significant
 * characters (< > & /) are not escaped.
 */
final class JsonString {
    private const string HEX = '0123456789abcdef';

    public static function quote(string $s): string {
        // Fast path: every byte is printable ASCII other than " and \ (safe set
        // 0x20,0x21,0x23-0x5B,0x5D-0x7E) -> emit verbatim. Anything else (escapes,
        // control bytes, or non-ASCII needing UTF-8 validation) takes the slow path.
        if (preg_match('/[^\x20\x21\x23-\x5B\x5D-\x7E]/', $s) === 0) {
            return '"' . $s . '"';
        }

        return self::quoteSlow($s);
    }

    private static function quoteSlow(string $s): string {
        $out = '"';
        $start = 0;
        $i = 0;
        $n = \strlen($s);
        while ($i < $n) {
            $b = \ord($s[$i]);
            if ($b < 0x80) {
                if ($b >= 0x20 && $b !== 0x5C && $b !== 0x22) {
                    ++$i;

                    continue;
                }
                $out .= substr($s, $start, $i - $start);
                $out .= match ($b) {
                    0x5C => '\\\\',
                    0x22 => '\\"',
                    0x08 => '\\b',
                    0x0C => '\\f',
                    0x0A => '\\n',
                    0x0D => '\\r',
                    0x09 => '\\t',
                    default => '\\u00' . self::HEX[$b >> 4] . self::HEX[$b & 0xF],
                };
                ++$i;
                $start = $i;

                continue;
            }
            [$rune, $size] = Utf8::decodeRune($s, $i, $n);
            if ($rune === 0xFFFD && $size === 1) {
                $out .= substr($s, $start, $i - $start) . '\\ufffd';
                ++$i;
                $start = $i;

                continue;
            }
            $i += $size;
        }
        $out .= substr($s, $start);

        return $out . '"';
    }
}
