<?php

declare(strict_types=1);

namespace Mbolli\Ron\Tests;

use Mbolli\Ron\Ron;
use Mbolli\Ron\RonException;
use Mbolli\Ron\Vocabulary\VocabularyRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Drives the upstream typed-vocabulary corpus (testdata/vocabularies).
 *
 * Valid cases render byte-for-byte and round-trip back to the input value; invalid
 * payloads and profiles requiring an unsupported vocabulary must be rejected.
 */
final class VocabularyTest extends TestCase {
    use ComparesJson;

    private const string DIR = __DIR__ . '/corpus/ron/testdata/vocabularies';

    /** @param array<string, mixed> $case */
    #[DataProvider('provideValidCases')]
    public function testValid(array $case): void {
        $input = self::read($case['inputJSON']);

        $ron = Ron::fromJson(
            $input,
            pretty: true,
            canonical: true,
            vocabularies: $case['vocabularies'],
            registry: self::registry(),
        );
        self::assertSame(self::read($case['expectedRON']), $ron, $case['name']);

        // Produced RON parses back to the original value (validation is lossless).
        self::assertSameJsonValue(
            json_decode($input, true, flags: JSON_THROW_ON_ERROR),
            json_decode(Ron::toJson($ron), true, flags: JSON_THROW_ON_ERROR),
            $case['name'] . ' round-trip',
        );
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function provideValidCases(): iterable {
        foreach (self::manifest()['valid'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('provideInvalidCases')]
    public function testInvalid(array $case): void {
        $this->expectException(RonException::class);
        Ron::validate(self::read($case['inputJSON']), $case['vocabularies'], self::registry());
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function provideInvalidCases(): iterable {
        foreach (self::manifest()['invalid'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('provideInvalidProfileCases')]
    public function testInvalidProfile(array $case): void {
        $profile = json_decode(self::read($case['profile']), true, flags: JSON_THROW_ON_ERROR);
        $required = [];
        foreach ($profile['vocabularies'] as $uri => $enabled) {
            if ($enabled === true) {
                $required[] = $uri;
            }
        }

        $this->expectException(RonException::class);
        Ron::validate('{}', $required, self::registry());
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function provideInvalidProfileCases(): iterable {
        foreach (self::manifest()['invalidProfiles'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** The official registry plus the example custom vocabulary used by the custom fixtures. */
    private static function registry(): VocabularyRegistry {
        $registry = VocabularyRegistry::official();
        $registry->register('https://example.com/vocab/invoice/v1', [
            '#com.example/money' => static fn (mixed $p): bool => true,
            '#com.example/rating' => static fn (mixed $p): bool => true,
            '#com.example/tags' => static fn (mixed $p): bool => true,
        ]);

        return $registry;
    }

    /** @return array<string, mixed> */
    private static function manifest(): array {
        return json_decode((string) file_get_contents(self::DIR . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function read(string $relative): string {
        return (string) file_get_contents(self::DIR . '/' . $relative);
    }
}
