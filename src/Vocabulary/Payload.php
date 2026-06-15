<?php

declare(strict_types=1);

namespace Mbolli\Ron\Vocabulary;

use Mbolli\Ron\RonException;
use Mbolli\Ron\Value\RonNumber;
use Mbolli\Ron\Value\RonObject;

/**
 * Shared payload-shape primitives for vocabulary validators.
 *
 * Typed payloads arrive in the value model produced by JsonParser: scalars are
 * `null|bool|string`, numbers are {@see RonNumber} (verbatim source text), arrays
 * are `list<mixed>`, and objects are {@see RonObject}. JSON
 * forbids NaN/Infinity, so a RonNumber is finite unless its text overflows the
 * IEEE 754 double range (e.g. `1e400`), which these helpers reject for float checks.
 */
final class Payload {
    private const string INT64_MAX = '9223372036854775807';
    private const string INT64_MIN_MAG = '9223372036854775808';
    private const string UINT64_MAX = '18446744073709551615';

    /** Throws a uniform "invalid <tag> payload" error. */
    public static function reject(string $tag): never {
        throw new RonException('ron: invalid ' . $tag . ' payload');
    }

    /** A finite number payload (integer- or float-form RON number). */
    public static function isFloat(mixed $value): bool {
        return $value instanceof RonNumber && is_finite((float) $value->text);
    }

    /** A canonical base-10 integer within the signed 64-bit range. */
    public static function isInt64Number(mixed $value): bool {
        return $value instanceof RonNumber && self::isInt64($value->text);
    }

    /** Canonical base-10 integer text (optional `-`, no leading zeros, no `-0`). */
    public static function isCanonicalInt(string $text): bool {
        return preg_match('/^-?(0|[1-9][0-9]*)$/', $text) === 1 && $text !== '-0';
    }

    public static function isInt64(string $text): bool {
        if (!self::isCanonicalInt($text)) {
            return false;
        }
        if ($text[0] === '-') {
            return self::magnitudeAtMost(substr($text, 1), self::INT64_MIN_MAG);
        }

        return self::magnitudeAtMost($text, self::INT64_MAX);
    }

    public static function isUint64(string $text): bool {
        if (preg_match('/^(0|[1-9][0-9]*)$/', $text) !== 1) {
            return false;
        }

        return self::magnitudeAtMost($text, self::UINT64_MAX);
    }

    /** Compares two canonical non-negative integer strings (no leading zeros). */
    private static function magnitudeAtMost(string $digits, string $max): bool {
        $a = \strlen($digits);
        $b = \strlen($max);
        if ($a !== $b) {
            return $a < $b;
        }

        return strcmp($digits, $max) <= 0;
    }
}
