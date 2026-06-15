<?php

declare(strict_types=1);

namespace Mbolli\Ron\Tests;

use Mbolli\Ron\Ron;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Round-trips every accepted document from the external JSON conformance corpus
 * (nst/JSONTestSuite) to prove RON conversion and encode/decode are lossless over
 * a broad, independent set of valid JSON.
 */
final class JsonSuiteTest extends TestCase {
    use ComparesJson;

    private const DIR = __DIR__ . '/corpus/json';

    #[DataProvider('provideValidJsonRoundTripsCases')]
    public function testValidJsonRoundTrips(string $path): void {
        $raw = (string) file_get_contents($path);
        $expected = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        // JSON -> RON -> JSON preserves the value exactly (number text is preserved).
        $roundTripped = json_decode(Ron::toJson(Ron::fromJson($raw)), true, 512, JSON_THROW_ON_ERROR);
        self::assertSameJsonValue($expected, $roundTripped);

        // PHP value -> RON -> PHP value. Numbers are compared numerically (RON, like
        // JSON, has a single number type, so whole-valued floats may come back as ints).
        // Skipped when json_decode produced a non-finite float, which encode rejects.
        if (!self::hasNonFinite($expected)) {
            $back = Ron::decode(Ron::encode($expected));
            self::assertTrue(self::valuesMatch($expected, $back), 'encode/decode round-trip mismatch');
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function provideValidJsonRoundTripsCases(): iterable {
        $files = glob(self::DIR . '/test_parsing/y_*.json');
        self::assertNotEmpty($files, 'JSON corpus submodule missing; run: composer install');
        foreach ($files as $path) {
            yield basename($path) => [$path];
        }
    }

    private static function hasNonFinite(mixed $value): bool {
        if (\is_array($value)) {
            foreach ($value as $item) {
                if (self::hasNonFinite($item)) {
                    return true;
                }
            }

            return false;
        }

        return \is_float($value) && !is_finite($value);
    }

    private static function valuesMatch(mixed $a, mixed $b): bool {
        if (\is_array($a) && \is_array($b)) {
            if (\count($a) !== \count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!\array_key_exists($key, $b) || !self::valuesMatch($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }
        if ((\is_int($a) || \is_float($a)) && (\is_int($b) || \is_float($b))) {
            // Numeric equality: RON/JSON have one number type, so 1 and 1.0 are equal.
            // Spaceship compares numerically and is not rewritten by the strict-comparison fixer.
            return ($a <=> $b) === 0;
        }

        return $a === $b;
    }
}
