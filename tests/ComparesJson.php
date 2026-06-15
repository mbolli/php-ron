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
 * strictly.
 */
trait ComparesJson {
    private static function assertSameJsonValue(mixed $expected, mixed $actual, string $message = ''): void {
        self::assertSame(self::normalizeJson($expected), self::normalizeJson($actual), $message);
    }

    private static function normalizeJson(mixed $value): mixed {
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
