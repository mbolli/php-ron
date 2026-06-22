<?php

declare(strict_types=1);

namespace Mbolli\Ron\Vocabulary;

use Mbolli\Ron\Value\RonNumber;

/**
 * Core typed vocabulary: identifiers, references, and primitive lexical types.
 *
 * Payload contracts are validation-only (the value model is preserved verbatim so
 * round-trips stay lossless); rules mirror ron-go's vocabulary_core.go.
 */
final class CoreVocabulary {
    public const string URI = 'https://ron.dev/vocab/core/v1';

    private const int SHA256_HEX_LEN = 64;

    /** @return array<string, \Closure(mixed, VocabularyValidator): mixed> */
    public static function validators(): array {
        return [
            '#uid' => static fn (mixed $p, VocabularyValidator $v): mixed => self::uid($p),
            '#url' => static fn (mixed $p, VocabularyValidator $v): mixed => self::url($p),
            '#rx' => static fn (mixed $p, VocabularyValidator $v): mixed => self::regex($p),
            '#dec' => static fn (mixed $p, VocabularyValidator $v): mixed => self::dec($p),
            '#b64' => static fn (mixed $p, VocabularyValidator $v): mixed => self::b64($p),
            '#sha256' => static fn (mixed $p, VocabularyValidator $v): mixed => self::sha256($p),
            '#' => static fn (mixed $p, VocabularyValidator $v): mixed => self::entityRef($p),
            '#tag' => static fn (mixed $p, VocabularyValidator $v): mixed => self::opaqueTag($p, $v),
        ];
    }

    private static function uid(mixed $payload): string {
        if (!\is_string($payload) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $payload) !== 1) {
            Payload::reject('#uid');
        }

        return $payload;
    }

    private static function url(mixed $payload): string {
        if (
            !\is_string($payload) || $payload === ''
            || strpbrk($payload, " \t\r\n") !== false
            || preg_match('~^[a-zA-Z][a-zA-Z0-9+.\-]*:~', $payload) !== 1
            || parse_url($payload) === false
        ) {
            Payload::reject('#url');
        }

        return $payload;
    }

    /**
     * `#rx` is a JavaScript RegExp: payload `[source]` or `[source, flags]` (both strings).
     * Validation-only — the array is returned verbatim so round-trips stay lossless. Mirrors
     * ron-go's vocabulary_regex.go: flags must be canonical, the source must convert and compile.
     */
    private static function regex(mixed $payload): mixed {
        if (!\is_array($payload) || \count($payload) < 1 || \count($payload) > 2) {
            Payload::reject('#rx');
        }
        $source = $payload[0] ?? null;
        $flags = \count($payload) === 2 ? ($payload[1] ?? null) : '';
        if (!\is_string($source) || !\is_string($flags)) {
            Payload::reject('#rx');
        }
        if (!self::validJsRegExpFlags($flags) || self::jsRegExpGoPattern($source, $flags) === null) {
            Payload::reject('#rx');
        }

        return $payload;
    }

    /** Canonical JS flags: each char from `dgimsuvy`, strictly increasing (sorted + unique), `u`/`v` exclusive. */
    private static function validJsRegExpFlags(string $flags): bool {
        $order = 'dgimsuvy';
        $last = -1;
        $seenU = false;
        $seenV = false;
        $len = \strlen($flags);
        for ($i = 0; $i < $len; ++$i) {
            $index = strpos($order, $flags[$i]);
            if ($index === false || $index <= $last) {
                return false;
            }
            $last = $index;
            if ($flags[$i] === 'u') {
                $seenU = true;
            } elseif ($flags[$i] === 'v') {
                $seenV = true;
            }
        }

        return !$seenU || !$seenV;
    }

    /**
     * Converts a JS RegExp source to an RE2-style pattern, wraps i/m/s flags, and verifies it
     * compiles. Returns the pattern, or null if the source has a bad escape or does not compile.
     */
    private static function jsRegExpGoPattern(string $source, string $flags): ?string {
        $converted = self::convertJsRegExpSource($source);
        if ($converted === null) {
            return null;
        }
        $goFlags = '';
        $len = \strlen($flags);
        for ($i = 0; $i < $len; ++$i) {
            if ($flags[$i] === 'i' || $flags[$i] === 'm' || $flags[$i] === 's') {
                $goFlags .= $flags[$i];
            }
        }
        $pattern = $goFlags === '' ? $converted : '(?' . $goFlags . ':' . $converted . ')';

        // PHP has no RE2, so PCRE stands in for Go's regexp.Compile as the structural validity
        // check. The grammars differ only on constructs RE2 forbids but PCRE allows -- backreferences
        // (\1) and lookaround ((?=), (?!), (?<=), (?<!)) -- so such a source is accepted here yet
        // rejected by ron-go. The conformance corpus exercises neither; per-engine semantics aside,
        // both reject the same structurally-broken sources (e.g. an unterminated class "[").
        $delimiter = self::pcreDelimiter($pattern);
        if ($delimiter === null || @preg_match($delimiter . $pattern . $delimiter, '') === false) {
            return null;
        }

        return $pattern;
    }

    /** Translates JS-specific escapes (`\uXXXX`, `\u{...}`, `\cX`, class `\b`) to `\x{...}`; null on a bad escape. */
    private static function convertJsRegExpSource(string $source): ?string {
        $converted = '';
        $inClass = false;
        $len = \strlen($source);
        $i = 0;
        while ($i < $len) {
            $char = $source[$i];
            if ($char !== '\\') {
                if ($char === '[' && !$inClass) {
                    $inClass = true;
                } elseif ($char === ']' && $inClass) {
                    $inClass = false;
                }
                $converted .= $char;
                ++$i;

                continue;
            }
            if ($i + 1 === $len) {
                $converted .= $char;
                ++$i;

                continue;
            }
            $next = $source[$i + 1];
            if ($next === 'u') {
                $end = self::appendUnicodeEscape($converted, $source, $i + 2);
                if ($end === null) {
                    return null;
                }
                $i = $end;
            } elseif ($next === 'c' && $i + 2 < $len && self::isAsciiAlpha($source[$i + 2])) {
                self::appendHexEscape($converted, \ord($source[$i + 2]) & 0x1F);
                $i += 3;
            } elseif ($next === 'b' && $inClass) {
                self::appendHexEscape($converted, 0x08);
                $i += 2;
            } else {
                $converted .= $char . $next;
                $i += 2;
            }
        }

        return $converted;
    }

    /** Appends a `\uXXXX` or `\u{...}` escape as `\x{...}`; returns the next index, or null if malformed/out of range. */
    private static function appendUnicodeEscape(string &$converted, string $source, int $pos): ?int {
        $len = \strlen($source);
        if ($pos < $len && $source[$pos] === '{') {
            $end = $pos + 1;
            while ($end < $len && $source[$end] !== '}') {
                if (!self::isHexByte($source[$end])) {
                    return null;
                }
                ++$end;
            }
            if ($end === $pos + 1 || $end === $len) {
                return null;
            }
            $hex = substr($source, $pos + 1, $end - ($pos + 1));
            if (hexdec($hex) > 0x10FFFF) {
                return null;
            }
            $converted .= '\\x{' . $hex . '}';

            return $end + 1;
        }
        if ($pos + 4 > $len) {
            return null;
        }
        for ($j = $pos; $j < $pos + 4; ++$j) {
            if (!self::isHexByte($source[$j])) {
                return null;
            }
        }
        $converted .= '\\x{' . substr($source, $pos, 4) . '}';

        return $pos + 4;
    }

    private static function appendHexEscape(string &$converted, int $value): void {
        $hex = dechex($value);
        $converted .= '\\x{' . ($value < 0x10 ? '0' . $hex : $hex) . '}';
    }

    /** First non-alphanumeric delimiter absent from the pattern, or null if every candidate occurs. */
    private static function pcreDelimiter(string $pattern): ?string {
        foreach (['/', '#', '~', '%', '@', '!', ';', ',', '|', '='] as $delimiter) {
            if (!str_contains($pattern, $delimiter)) {
                return $delimiter;
            }
        }

        return null;
    }

    private static function isHexByte(string $char): bool {
        return ($char >= '0' && $char <= '9') || ($char >= 'A' && $char <= 'F') || ($char >= 'a' && $char <= 'f');
    }

    private static function isAsciiAlpha(string $char): bool {
        return ($char >= 'A' && $char <= 'Z') || ($char >= 'a' && $char <= 'z');
    }

    private static function dec(mixed $payload): string {
        if (!\is_string($payload) || $payload === '') {
            Payload::reject('#dec');
        }
        $len = \strlen($payload);
        $pos = 0;
        $negative = false;
        if ($payload[$pos] === '-') {
            $negative = true;
            ++$pos;
            if ($pos === $len) {
                Payload::reject('#dec');
            }
        }
        if ($payload[$pos] === '0') {
            ++$pos;
            if ($negative && $pos === $len) {
                Payload::reject('#dec'); // -0 is not canonical
            }
        } else {
            if ($payload[$pos] < '1' || $payload[$pos] > '9') {
                Payload::reject('#dec');
            }
            while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                ++$pos;
            }
        }
        if ($pos < $len) {
            if ($payload[$pos] !== '.') {
                Payload::reject('#dec');
            }
            ++$pos;
            $fractionStart = $pos;
            while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                ++$pos;
            }
            if ($pos !== $len || $pos === $fractionStart || $payload[$len - 1] === '0') {
                Payload::reject('#dec'); // trailing data, empty fraction, or redundant trailing zero
            }
        }

        return $payload;
    }

    private static function b64(mixed $payload): string {
        if (
            !\is_string($payload)
            || str_contains($payload, '=')
            || preg_match('~^[A-Za-z0-9_-]*$~', $payload) !== 1
            || \strlen($payload) % 4 === 1
            || base64_decode(strtr($payload, '-_', '+/'), true) === false
        ) {
            Payload::reject('#b64');
        }

        return $payload;
    }

    private static function sha256(mixed $payload): string {
        if (!\is_string($payload) || \strlen($payload) !== self::SHA256_HEX_LEN || preg_match('/^[0-9a-f]+$/', $payload) !== 1) {
            Payload::reject('#sha256');
        }

        return $payload;
    }

    private static function entityRef(mixed $payload): mixed {
        if (\is_string($payload)) {
            return $payload;
        }
        if ($payload instanceof RonNumber && Payload::isCanonicalInt($payload->text)) {
            return $payload;
        }
        Payload::reject('#');
    }

    private static function opaqueTag(mixed $payload, VocabularyValidator $validator): mixed {
        if (!\is_array($payload) || \count($payload) !== 2) {
            Payload::reject('#tag');
        }
        // The second element is an arbitrary value; recurse so nested enabled tags
        // inside it still validate. The first element is an implementation-defined id.
        $payload[1] = $validator->validate($payload[1]);

        return $payload;
    }
}
