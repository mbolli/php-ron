<?php

declare(strict_types=1);

namespace Mbolli\Ron\Tests;

use Mbolli\Ron\Ron;
use PHPUnit\Framework\TestCase;

/**
 * Targeted edge cases beyond the corpus goldens.
 */
final class UnitTest extends TestCase {
    use ComparesJson;

    public function testBigIntegerTextIsPreserved(): void {
        // Beyond PHP's int range: must survive as text, not collapse to a float.
        self::assertSame('{"n":9223372036854775808}', Ron::toJson('n 9223372036854775808'));
        self::assertSame('n 9223372036854775808', Ron::canonicalRon('{"n":9223372036854775808}'));
    }

    public function testExponentNumberTextIsPreserved(): void {
        self::assertSame('{"v":-12.5e+2}', Ron::toJson('v -12.5e+2'));
        self::assertSame('v -12.5e+2', Ron::canonicalRon('{"v":-12.5e+2}'));
    }

    public function testCommaPrefixedTokenIsString(): void {
        self::assertSame('[",foo"]', Ron::toJson('[,foo]'));
        self::assertSame('[","]', Ron::toJson('[,]'));
    }

    public function testStandaloneApostropheToken(): void {
        self::assertSame('["\'"]', Ron::toJson("[ ' ]"));
    }

    public function testQuotedStringDelimiterGrows(): void {
        // longest run of apostrophes in the value + 1 delimiter
        self::assertSame("''it's fine''", Ron::canonicalRon('"it\'s fine"'));
        self::assertSame("'''''", Ron::canonicalRon('"\'"'));
    }

    public function testNonAsciiPassesThroughRaw(): void {
        self::assertSame('åß∂ƒ', Ron::fromJson('"åß∂ƒ"', pretty: false));
        self::assertSame('"åß∂ƒ"', Ron::toJson(Ron::fromJson('"åß∂ƒ"', pretty: false)));
    }

    public function testInlineArrayBoundaryAt80Bytes(): void {
        // Single-char elements: inline size = 2 + n + (n - 1) = 2n + 1.
        $inline = json_encode(array_fill(0, 39, 'a')); // size 79 -> inline
        $broken = json_encode(array_fill(0, 40, 'a')); // size 81 -> multiline

        self::assertStringNotContainsString("\n", rtrim(Ron::fromJson((string) $inline), "\n"));
        self::assertStringContainsString("\n", rtrim(Ron::fromJson((string) $broken), "\n"));
    }

    public function testInlineObjectOnlySingleKey(): void {
        // One key inlines; two keys never inline (per the spec/reference).
        self::assertSame("a {b 1}\n", Ron::fromJson('{"a":{"b":1}}'));
        self::assertSame("a {\n  b 1\n  c 2\n}\n", Ron::fromJson('{"a":{"b":1,"c":2}}'));
    }

    public function testRootScalarRoundTrips(): void {
        self::assertSame('true', Ron::toJson('true'));
        self::assertSame('null', Ron::toJson('null'));
        self::assertSame('123', Ron::toJson('123'));
        self::assertSame('"hello"', Ron::toJson('hello'));
    }

    public function testTypedObjectsPassThroughLosslessly(): void {
        // Base RON treats vocabulary tags as ordinary objects: round-trip is lossless.
        $json = (string) file_get_contents(
            __DIR__ . '/corpus/ron/testdata/vocabularies/core/input.json',
        );
        $ron = Ron::fromJson($json);
        self::assertSameJsonValue(
            json_decode($json, true, flags: JSON_THROW_ON_ERROR),
            json_decode(Ron::toJson($ron), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testCanonicalHashMatchesManualHash(): void {
        $json = '{"b":2,"a":1}';
        self::assertSame(hash('xxh128', Ron::canonicalRon($json)), Ron::canonicalHash($json));
    }
}
