<?php

declare(strict_types=1);

namespace Mbolli\Ron\Tests;

use Mbolli\Ron\Ron;
use Mbolli\Ron\RonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Drives the upstream RON conformance corpus (testdata/conformance).
 */
final class ConformanceTest extends TestCase {
    use ComparesJson;

    private const DIR = __DIR__ . '/corpus/ron/testdata/conformance';

    /** @param array<string, mixed> $case */
    #[DataProvider('provideValidCases')]
    public function testValid(array $case): void {
        $jsonInput = self::read($case['jsonInput']);

        // RON -> JSON, for every RON input form.
        foreach ($case['ronInputs'] as $ronPath) {
            $ron = self::read($ronPath);

            if (isset($case['expectedCompactJSON'])) {
                $compactJson = Ron::toJson($ron, pretty: false, canonical: true);
                self::assertSame(self::read($case['expectedCompactJSON']), $compactJson, "compact JSON for {$ronPath}");
                self::assertJsonStructure($jsonInput, $compactJson, "compact JSON round-trip for {$ronPath}");
            }
            if (isset($case['expectedPrettyJSON'])) {
                $prettyJson = Ron::toJson($ron, pretty: true, canonical: true);
                self::assertSame(self::read($case['expectedPrettyJSON']), $prettyJson, "pretty JSON for {$ronPath}");
                self::assertJsonStructure($jsonInput, $prettyJson, "pretty JSON round-trip for {$ronPath}");
            }

            // RON -> (RON via JSON) round-trips back to the same value.
            self::assertJsonStructure($jsonInput, Ron::toJson($ron), "RON round-trip for {$ronPath}");
        }

        // JSON -> RON.
        if (isset($case['expectedPrettyRON'])) {
            $prettyRon = Ron::fromJson($jsonInput, pretty: true, canonical: true);
            self::assertSame(self::read($case['expectedPrettyRON']), $prettyRon, 'pretty RON');
            self::assertJsonStructure($jsonInput, Ron::toJson($prettyRon), 'pretty RON round-trip');
        }
        if (isset($case['expectedCompactRON'])) {
            $compactRon = Ron::fromJson($jsonInput, pretty: false, canonical: true);
            self::assertSame(self::read($case['expectedCompactRON']), $compactRon, 'compact RON');
            self::assertJsonStructure($jsonInput, Ron::toJson($compactRon), 'compact RON round-trip');

            if (isset($case['expectedCanonicalRONSHA256'])) {
                self::assertSame(
                    $case['expectedCanonicalRONSHA256'],
                    hash('sha256', $compactRon),
                    'canonical RON SHA-256',
                );
            }
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function provideValidCases(): iterable {
        foreach (self::manifest()['valid'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    #[DataProvider('provideInvalidRonCases')]
    public function testInvalidRon(string $path): void {
        $this->expectException(RonException::class);
        Ron::toJson(self::read($path));
    }

    /** @return iterable<string, array{0: string}> */
    public static function provideInvalidRonCases(): iterable {
        foreach (self::manifest()['invalidRON'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('provideInvalidJsonCases')]
    public function testInvalidJson(string $path): void {
        $this->expectException(RonException::class);
        Ron::fromJson(self::read($path));
    }

    /** @return iterable<string, array{0: string}> */
    public static function provideInvalidJsonCases(): iterable {
        foreach (self::manifest()['invalidJSON'] as $path) {
            yield $path => [$path];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('provideRenderingCases')]
    public function testRendering(array $case): void {
        $jsonInput = self::read($case['jsonInput']);
        $options = $case['options'];
        $hooks = $case['typedValueHooks'] ?? [];

        $mapper = $hooks === [] ? null : static function (array $path, mixed $value) use ($hooks): array {
            foreach ($hooks as $hook) {
                if ($path === $hook['path']) {
                    return [$hook['replaceWith'], true];
                }
            }

            return [null, false];
        };

        $ron = Ron::fromJson($jsonInput, $options['isPretty'], $options['isCanonical'], $mapper);
        self::assertSame(self::read($case['expectedRON']), $ron, 'rendered RON');

        // Round-trip: produced RON parses back to the transformed value.
        $expected = self::applyHooks(json_decode($jsonInput, true, flags: JSON_THROW_ON_ERROR), $hooks);
        $roundTrip = json_decode(Ron::toJson($ron), true, flags: JSON_THROW_ON_ERROR);
        self::assertSameJsonValue($expected, $roundTrip, 'rendered RON round-trip');
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function provideRenderingCases(): iterable {
        foreach (self::manifest()['jsonToRONRendering'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @return array<string, mixed> */
    private static function manifest(): array {
        return json_decode((string) file_get_contents(self::DIR . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function read(string $relative): string {
        return (string) file_get_contents(self::DIR . '/' . $relative);
    }

    private static function assertJsonStructure(string $expectedJson, string $actualJson, string $message): void {
        self::assertSameJsonValue(
            json_decode($expectedJson, true, flags: JSON_THROW_ON_ERROR),
            json_decode($actualJson, true, flags: JSON_THROW_ON_ERROR),
            $message,
        );
    }

    /**
     * @param list<array{path: list<int|string>, replaceWith: mixed}> $hooks
     */
    private static function applyHooks(mixed $value, array $hooks): mixed {
        foreach ($hooks as $hook) {
            $value = self::setPath($value, $hook['path'], $hook['replaceWith']);
        }

        return $value;
    }

    /** @param list<int|string> $path */
    private static function setPath(mixed $value, array $path, mixed $replacement): mixed {
        if ($path === []) {
            return $replacement;
        }
        $head = array_shift($path);
        $value[$head] = self::setPath($value[$head], $path, $replacement);

        return $value;
    }
}
