<?php

declare(strict_types=1);

namespace Mbolli\Ron\Tests;

use Mbolli\Ron\Ron;
use Mbolli\Ron\RonException;
use Mbolli\Ron\RonMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Drives the upstream RON conformance corpus (testdata/conformance).
 */
final class ConformanceTest extends TestCase {
    use ComparesJson;

    private const DIR = __DIR__ . '/corpus/ron/testdata/conformance';

    /** Manifest key infix -> mode, for the three `expected<Mode>JSON`/`RON` goldens. */
    private const array MODES = [
        'Pretty' => RonMode::Pretty,
        'Compact' => RonMode::Compact,
        'Canonical' => RonMode::Canonical,
    ];

    /** @param array<string, mixed> $case */
    #[DataProvider('provideValidCases')]
    public function testValid(array $case): void {
        $jsonInput = self::read($case['jsonInput']);

        // RON -> JSON, for every RON input form.
        foreach ($case['ronInputs'] as $ronPath) {
            $ron = self::read($ronPath);

            foreach (self::MODES as $key => $mode) {
                $json = Ron::toJson($ron, $mode);
                self::assertSame(self::read($case["expected{$key}JSON"]), $json, "{$mode->value} JSON for {$ronPath}");
                self::assertJsonStructure($jsonInput, $json, "{$mode->value} JSON round-trip for {$ronPath}");
            }
            self::assertSame(
                $case['expectedCanonicalJSONSHA256'],
                hash('sha256', Ron::toJson($ron, RonMode::Canonical)),
                "canonical JSON SHA-256 for {$ronPath}",
            );
        }

        // JSON -> RON.
        foreach (self::MODES as $key => $mode) {
            $ron = Ron::fromJson($jsonInput, $mode);
            self::assertSame(self::read($case["expected{$key}RON"]), $ron, "{$mode->value} RON");
            self::assertJsonStructure($jsonInput, Ron::toJson($ron), "{$mode->value} RON round-trip");
        }
        self::assertSame(
            $case['expectedCanonicalRONSHA256'],
            hash('sha256', Ron::fromJson($jsonInput, RonMode::Canonical)),
            'canonical RON SHA-256',
        );
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function provideValidCases(): iterable {
        foreach (self::manifest()['valid'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('provideCanonicalRonFromRonCases')]
    public function testCanonicalRonFromRon(array $case): void {
        $canonical = Ron::format(self::read($case['inputRON']), RonMode::Canonical);

        self::assertSame(self::read($case['expectedCanonicalRON']), $canonical, 'canonical RON');
        self::assertSame($case['expectedCanonicalRONSHA256'], hash('sha256', $canonical), 'canonical RON SHA-256');
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function provideCanonicalRonFromRonCases(): iterable {
        foreach (self::manifest()['canonicalRON']['validRON'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    #[DataProvider('provideInvalidCanonicalRonCases')]
    public function testInvalidCanonicalRon(string $path): void {
        // Valid base RON: only the canonical contract rejects these.
        Ron::format(self::read($path), RonMode::Compact);

        $this->expectException(RonException::class);
        Ron::format(self::read($path), RonMode::Canonical);
    }

    /** @return iterable<string, array{0: string}> */
    public static function provideInvalidCanonicalRonCases(): iterable {
        foreach (self::manifest()['canonicalRON']['invalidRON'] as $path) {
            yield $path => [$path];
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

        $ron = Ron::fromJson($jsonInput, RonMode::from($options['mode']), $mapper);
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
