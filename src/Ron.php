<?php

declare(strict_types=1);

namespace Mbolli\Ron;

use Mbolli\Ron\Value\RonToken;
use Mbolli\Ron\Vocabulary\VocabularyRegistry;
use Mbolli\Ron\Vocabulary\VocabularyValidator;

/**
 * Public facade for RON <-> JSON conversion.
 *
 * Defaults mirror ron-go: every conversion takes one {@see RonMode} and defaults to
 * {@see RonMode::Pretty}, which preserves source member order. Only
 * {@see RonMode::Canonical} sorts keys, and it applies the RFC 8785 / I-JSON contract
 * rather than just reordering.
 */
final class Ron {
    /** Library version (semver). */
    public const string VERSION = '0.5.0';

    /**
     * Default maximum nesting depth for the recursive parsers, mirroring
     * json_encode/json_decode. Input nested deeper throws a RonException rather
     * than overflowing the stack.
     */
    public const int DEFAULT_MAX_DEPTH = 512;

    /** Default enabled vocabularies: core on, everything else opt-in. */
    public const array DEFAULT_VOCABULARIES = [VocabularyRegistry::CORE_V1];

    /**
     * Convert RON to JSON.
     *
     * Pretty and compact preserve source member order. Canonical produces RFC 8785
     * canonical JSON, which normalizes numbers and rejects the I-JSON violations
     * listed on {@see RonMode::Canonical}.
     *
     * @param int $maxDepth reject input nested deeper than this many levels
     */
    public static function toJson(
        string $ron,
        RonMode $mode = RonMode::Pretty,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
    ): string {
        return (new RonToJson($ron))->convert($mode, $maxDepth);
    }

    /**
     * Convert JSON to RON.
     *
     * When $vocabularies is non-empty, the parsed value is validated against those
     * typed vocabularies before rendering (invalid typed payloads throw a
     * RonException); pass `[]` to skip validation entirely. Unknown typed values
     * remain ordinary objects. $registry defaults to the eight built-in vocabularies.
     *
     * @param null|(callable(list<int|string>, mixed): array{0: mixed, 1: bool}) $mapper
     *                                                                                         optional typed-value render hook: receives (path, value) and returns
     *                                                                                         [replacement, replaced]
     * @param int                                                                $maxDepth     reject input nested deeper than this many levels
     * @param list<string>                                                       $vocabularies enabled typed vocabulary URIs
     */
    public static function fromJson(
        string $json,
        RonMode $mode = RonMode::Pretty,
        ?callable $mapper = null,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
        array $vocabularies = self::DEFAULT_VOCABULARIES,
        ?VocabularyRegistry $registry = null,
    ): string {
        if ($mode === RonMode::Canonical) {
            // Canonicalize first: that single step applies the whole RFC 8785 and
            // I-JSON contract (duplicate decoded names, lone surrogates, noncharacters,
            // non-finite numbers) and rewrites number spelling, leaving the RON renderer
            // with nothing to do but sort keys and emit compact bytes. Only a typed-value
            // mapper, which needs JsonParser's path tracking, pays for the JSON round trip.
            $value = $mapper === null
                ? Rfc8785::canonicalModel($json, $maxDepth)
                : (new JsonParser(Rfc8785::canonicalize($json, $maxDepth), $mapper, $maxDepth))->parse();
        } else {
            $value = (new JsonParser($json, $mapper, $maxDepth))->parse();
        }
        if ($vocabularies !== []) {
            $value = (new VocabularyValidator($registry ?? VocabularyRegistry::official(), $vocabularies))->validate($value);
        }

        return (new RonRenderer($mode))->render($value);
    }

    /**
     * Validate a JSON document against the enabled typed vocabularies.
     *
     * Throws a RonException when a typed payload is invalid or when an enabled
     * vocabulary URI is not supported by the registry. Does not render or return a
     * value; use it for profile checks and standalone validation.
     *
     * @param list<string> $vocabularies enabled typed vocabulary URIs
     */
    public static function validate(
        string $json,
        array $vocabularies = self::DEFAULT_VOCABULARIES,
        ?VocabularyRegistry $registry = null,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
    ): void {
        $value = (new JsonParser($json, null, $maxDepth))->parse();
        (new VocabularyValidator($registry ?? VocabularyRegistry::official(), $vocabularies))->validate($value);
    }

    /**
     * Encode an arbitrary PHP value as RON, like json_encode for JSON.
     *
     * Accepts the same value space as json_encode (scalars, lists, maps, objects,
     * and JsonSerializable). Non-finite floats, resources and unencodable types
     * throw a RonException.
     */
    public static function encode(
        mixed $value,
        RonMode $mode = RonMode::Pretty,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
    ): string {
        return (new RonRenderer($mode))->render(Encoder::toModel($value, $maxDepth));
    }

    /**
     * Decode RON to a PHP value, like json_decode for JSON.
     *
     * Numbers follow json_decode semantics (integers beyond PHP's int range become
     * floats); use toJson() if you need to preserve number text.
     *
     * @return mixed associative arrays when $associative is true, stdClass otherwise
     */
    public static function decode(string $ron, bool $associative = true, int $maxDepth = self::DEFAULT_MAX_DEPTH): mixed {
        // toJson is the authoritative depth gate; give json_decode one extra level so it
        // never rejects nesting that toJson already accepted.
        return json_decode(
            self::toJson($ron, RonMode::Compact, $maxDepth),
            $associative,
            max(1, $maxDepth + 1),
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Compact, canonically-ordered RON (the canonical RON byte form).
     *
     * @param list<string> $vocabularies enabled typed vocabulary URIs
     */
    public static function canonicalRon(
        string $json,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
        array $vocabularies = self::DEFAULT_VOCABULARIES,
        ?VocabularyRegistry $registry = null,
    ): string {
        return self::fromJson($json, RonMode::Canonical, maxDepth: $maxDepth, vocabularies: $vocabularies, registry: $registry);
    }

    /**
     * Re-render RON source in the given mode (RON -> RON).
     *
     * Typed vocabularies are not applied: this reformats an existing document rather
     * than validating one. Use it to derive canonical RON, and therefore the canonical
     * hash, from RON input instead of JSON.
     */
    public static function format(
        string $ron,
        RonMode $mode = RonMode::Pretty,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
    ): string {
        // Compact JSON is the order-preserving intermediate; canonical mode has to go
        // through canonical JSON so the I-JSON checks see the RON-sourced value.
        $json = self::toJson($ron, $mode === RonMode::Canonical ? RonMode::Canonical : RonMode::Compact, $maxDepth);

        return self::fromJson($json, $mode, maxDepth: $maxDepth, vocabularies: []);
    }

    /**
     * SHA-256 of the canonical RON bytes, as 64 lowercase hex digits.
     *
     * @param list<string> $vocabularies enabled typed vocabulary URIs
     */
    public static function canonicalHash(
        string $json,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
        array $vocabularies = self::DEFAULT_VOCABULARIES,
        ?VocabularyRegistry $registry = null,
    ): string {
        return hash('sha256', self::canonicalRon($json, $maxDepth, $vocabularies, $registry));
    }

    /** RFC 8785 (JCS) canonical JSON. Distinct from compact JSON: numbers are normalized. */
    public static function canonicalJson(string $json, int $maxDepth = self::DEFAULT_MAX_DEPTH): string {
        return Rfc8785::canonicalize($json, $maxDepth);
    }

    /**
     * Role-aware token stream over RON source, for tooling such as syntax highlighters.
     *
     * Each token carries a byte source span (offset + length) and a lexical role; keys
     * are reported separately from string values, so consumers need not re-derive RON's
     * key/value context. Unlike toJson(), this is best-effort: malformed input never
     * throws, it just yields tokens for as much of the input as could be classified.
     *
     * @return list<RonToken>
     */
    public static function tokenize(string $ron, int $maxDepth = self::DEFAULT_MAX_DEPTH): array {
        return (new RonTokenizer($ron))->tokenize($maxDepth);
    }
}
