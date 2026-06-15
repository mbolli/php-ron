<?php

declare(strict_types=1);

namespace Mbolli\Ron\Tests;

use Mbolli\Ron\Ron;
use Mbolli\Ron\RonException;
use Mbolli\Ron\Vocabulary\VocabularyRegistry;
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

    public function testTypedValueInlinePayloadAllowsMultiKey(): void {
        // A single '#'-prefixed key is a typed value: its payload may inline even as
        // a multi-key object, unlike the base single-key-only inline rule above.
        self::assertSame(
            "point {#geo {coordinates [-73.9857 40.7484] type Point}}\n",
            Ron::fromJson('{"point":{"#geo":{"type":"Point","coordinates":[-73.9857,40.7484]}}}'),
        );
        // Typed values nested inside arrays collapse the same way.
        self::assertSame(
            "opaque {#tag [127 {mode raw value [1 2 3]}]}\n",
            Ron::fromJson('{"opaque":{"#tag":[127,{"mode":"raw","value":[1,2,3]}]}}'),
        );
        // The entity-ref tag is the bare key '#'.
        self::assertSame("parent {# 300}\n", Ron::fromJson('{"parent":{"#":300}}'));
    }

    public function testTypedValueMultilineCollapsesWrapper(): void {
        // When the payload exceeds the inline budget the wrapper stays transparent to
        // indentation: members sit one level in and the braces close together (`}}`).
        $json = '{"x":{"#note":{"count":42,"description":"this is a reasonably long description value that exceeds the budget"}}}';
        self::assertSame(
            "x {#note {\n  count 42\n  description 'this is a reasonably long description value that exceeds the budget'\n}}\n",
            Ron::fromJson($json),
        );
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
        self::assertSame(hash('sha256', Ron::canonicalRon($json)), Ron::canonicalHash($json));
    }

    public function testCoreVocabularyValidatesByDefault(): void {
        // Core is enabled by default, so a malformed core payload is rejected.
        $this->expectException(RonException::class);
        Ron::fromJson('{"bad":{"#uid":"not-a-uuid"}}');
    }

    public function testNonCoreVocabularyIsOptIn(): void {
        // Time is not enabled by default: an invalid #dur passes through untouched.
        self::assertSame("bad {#dur P1M}\n", Ron::fromJson('{"bad":{"#dur":"P1M"}}'));
        // ...but is rejected once the time vocabulary is enabled explicitly.
        $this->expectException(RonException::class);
        Ron::fromJson('{"bad":{"#dur":"P1M"}}', vocabularies: [VocabularyRegistry::TIME_V1]);
    }

    public function testVocabularyValidationCanBeDisabled(): void {
        // Passing no vocabularies skips validation entirely, even for core.
        self::assertSame("bad {#uid not-a-uuid}\n", Ron::fromJson('{"bad":{"#uid":"not-a-uuid"}}', vocabularies: []));
    }

    public function testTypedValueWithExtraMemberIsRejected(): void {
        // An object carrying an enabled tag must have exactly one member.
        $this->expectException(RonException::class);
        Ron::validate('{"bad":{"#uid":"00112233-4455-6677-8899-aabbccddeeff","extra":true}}');
    }

    public function testCustomVocabularyRegistration(): void {
        $uri = 'https://example.com/vocab/money/v1';
        $registry = VocabularyRegistry::official();
        $registry->register($uri, [
            // A validator returns true to accept, false to reject (or throws).
            '#com.example/money' => static fn (mixed $payload): bool => \is_array($payload) && \count($payload) === 2,
        ]);

        $json = '{"price":{"#com.example/money":["USD","123.45"]}}';
        self::assertSame("price {#com.example/money [USD '123.45']}\n", Ron::fromJson($json, vocabularies: [$uri], registry: $registry));

        $this->expectException(RonException::class);
        Ron::validate('{"price":{"#com.example/money":["USD"]}}', [$uri], $registry);
    }

    public function testCustomVocabularyCanTransformPayload(): void {
        // A validator that returns a value other than true/false replaces the payload.
        $uri = 'https://example.com/vocab/shout/v1';
        $registry = VocabularyRegistry::official();
        $registry->register($uri, [
            '#com.example/shout' => static fn (mixed $payload): mixed => \is_string($payload) ? strtoupper($payload) : false,
        ]);

        self::assertSame(
            "msg {#com.example/shout HELLO}\n",
            Ron::fromJson('{"msg":{"#com.example/shout":"hello"}}', vocabularies: [$uri], registry: $registry),
        );

        $this->expectException(RonException::class);
        Ron::validate('{"msg":{"#com.example/shout":42}}', [$uri], $registry);
    }

    public function testCustomVocabularyCanReplaceWithBoolean(): void {
        // replace() forces a boolean through as the payload (a bare return would be
        // read as accept/reject).
        $uri = 'https://example.com/vocab/flag/v1';
        $registry = VocabularyRegistry::official();
        $registry->register($uri, [
            '#com.example/flag' => static fn (mixed $p): mixed => \is_string($p)
                ? VocabularyRegistry::replace($p === 'on')
                : false,
        ]);

        self::assertSame("beta {#com.example/flag true}\n", Ron::fromJson('{"beta":{"#com.example/flag":"on"}}', vocabularies: [$uri], registry: $registry));
        self::assertSame("beta {#com.example/flag false}\n", Ron::fromJson('{"beta":{"#com.example/flag":"x"}}', vocabularies: [$uri], registry: $registry));
    }

    public function testRequiredUnsupportedVocabularyIsRejected(): void {
        $this->expectException(RonException::class);
        Ron::validate('{}', ['https://example.com/unknown/v1']);
    }
}
