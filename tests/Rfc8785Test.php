<?php

declare(strict_types=1);

namespace Mbolli\Ron\Tests;

use Mbolli\Ron\Ron;
use Mbolli\Ron\RonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Drives the RFC 8785 (JCS) corpus (testdata/rfc8785).
 */
final class Rfc8785Test extends TestCase {
    private const DIR = __DIR__ . '/corpus/ron/testdata/rfc8785';

    /** @param array<string, mixed> $case */
    #[DataProvider('provideCanonicalizeCases')]
    public function testCanonicalize(array $case): void {
        $canonical = Ron::canonicalJson(self::read($case['inputJSON']));

        self::assertSame(self::read($case['expectedCanonicalJSON']), $canonical, 'canonical JSON bytes');
        self::assertSame(
            trim(self::read($case['expectedCanonicalUTF8Hex'])),
            bin2hex($canonical),
            'canonical UTF-8 hex',
        );
        self::assertSame($case['expectedCanonicalJSONXXH3'], hash('xxh128', $canonical), 'canonical JSON XXH3-128');
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function provideCanonicalizeCases(): iterable {
        foreach (self::manifest()['valid'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    #[DataProvider('provideAppendixBNumbersCases')]
    public function testAppendixBNumbers(string $ieee754Hex, string $expectedJson): void {
        $float = unpack('E', hex2bin($ieee754Hex))[1];
        // Wrap the value in an array so canonicalize serializes it as a JSON number.
        $canonical = Ron::canonicalJson('[' . self::floatLiteral($float) . ']');
        self::assertSame('[' . $expectedJson . ']', $canonical, $ieee754Hex);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function provideAppendixBNumbersCases(): iterable {
        $data = json_decode(self::read('numbers/appendix-b.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($data['finite'] as $i => $c) {
            yield ($c['comment'] ?? (string) $i) => [$c['ieee754Hex'], $c['expectedJSON']];
        }
    }

    #[DataProvider('provideInvalidCases')]
    public function testInvalid(string $path): void {
        $this->expectException(RonException::class);
        Ron::canonicalJson(self::read($path));
    }

    /** @return iterable<string, array{0: string}> */
    public static function provideInvalidCases(): iterable {
        foreach (self::manifest()['invalidIJSON'] as $case) {
            yield $case['name'] => [$case['inputJSON']];
        }
    }

    /** @return array<string, mixed> */
    private static function manifest(): array {
        return json_decode((string) file_get_contents(self::DIR . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function read(string $relative): string {
        return (string) file_get_contents(self::DIR . '/' . $relative);
    }

    /** Render a float as a JSON number literal that parses back to the exact value. */
    private static function floatLiteral(float $value): string {
        if (!is_finite($value)) {
            self::fail('non-finite test value');
        }
        for ($p = 0; $p <= 17; ++$p) {
            $s = \sprintf('%.' . $p . 'e', $value);
            if ((float) $s === $value) {
                return $s;
            }
        }

        return \sprintf('%.17e', $value);
    }
}
