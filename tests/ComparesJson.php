<?php

declare(strict_types=1);

namespace Mbolli\Ron\Tests;

/**
 * Order-independent structural comparison of decoded JSON values.
 *
 * Object key order is irrelevant (canonical output reorders keys), but array
 * element order is significant. PHPUnit's assertEqualsCanonicalizing sorts arrays
 * by value, which scrambles associative arrays of mixed-type values, so this
 * recursively ksorts associative arrays while preserving list order, then compares
 * strictly. Integer-valued floats are folded to int first: RON and JSON have a single
 * number type, and canonical output re-serializes 1E2 as 100, so the int/float
 * distinction PHP invents while decoding is not a real difference.
 */
trait ComparesJson {
    private static function assertSameJsonValue(mixed $expected, mixed $actual, string $message = ''): void {
        self::assertSame(self::normalizeJson($expected), self::normalizeJson($actual), $message);
    }

    private static function normalizeJson(mixed $value): mixed {
        // Guard the range too: casting a float beyond PHP_INT_MAX is undefined.
        if (
            \is_float($value) && is_finite($value)
            && $value >= (float) PHP_INT_MIN && $value <= (float) PHP_INT_MAX
            && (float) (int) $value === $value
        ) {
            return (int) $value;
        }
        if (\is_array($value)) {
            $isList = array_is_list($value);
            $value = array_map(self::normalizeJson(...), $value);
            if (!$isList) {
                ksort($value);
            }
        }

        return $value;
    }
}
