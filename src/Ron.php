<?php

declare(strict_types=1);

namespace Mbolli\Ron;

/**
 * Public facade for RON <-> JSON conversion.
 *
 * Defaults mirror ron-go: ToJSON produces compact, canonically-ordered JSON;
 * FromJSON produces pretty, canonically-ordered RON.
 */
final class Ron {
    /** Library version (semver). */
    public const string VERSION = '0.1.0';

    /**
     * Convert RON to JSON.
     *
     * @param bool $pretty    multiline output when true, compact when false
     * @param bool $canonical sort object keys by RFC 8785 UTF-16 order when true
     */
    public static function toJson(string $ron, bool $pretty = false, bool $canonical = true): string {
        return (new RonToJson($ron))->convert($pretty, $canonical);
    }

    /**
     * Convert JSON to RON.
     *
     * @param null|(callable(list<int|string>, mixed): array{0: mixed, 1: bool}) $mapper
     *                                                                                   optional typed-value render hook: receives (path, value) and returns
     *                                                                                   [replacement, replaced]
     */
    public static function fromJson(
        string $json,
        bool $pretty = true,
        bool $canonical = true,
        ?callable $mapper = null,
    ): string {
        $value = (new JsonParser($json, $mapper))->parse();

        return (new RonRenderer($pretty, $canonical))->render($value);
    }

    /**
     * Encode an arbitrary PHP value as RON, like json_encode for JSON.
     *
     * Accepts the same value space as json_encode (scalars, lists, maps, objects,
     * and JsonSerializable). Non-finite floats, resources and unencodable types
     * throw a RonException.
     */
    public static function encode(mixed $value, bool $pretty = true, bool $canonical = true): string {
        return (new RonRenderer($pretty, $canonical))->render(Encoder::toModel($value));
    }

    /**
     * Decode RON to a PHP value, like json_decode for JSON.
     *
     * Numbers follow json_decode semantics (integers beyond PHP's int range become
     * floats); use toJson() if you need to preserve number text.
     *
     * @return mixed associative arrays when $associative is true, stdClass otherwise
     */
    public static function decode(string $ron, bool $associative = true): mixed {
        return json_decode(self::toJson($ron), $associative, flags: JSON_THROW_ON_ERROR);
    }

    /** Compact, canonically-ordered RON (the canonical RON byte form). */
    public static function canonicalRon(string $json): string {
        return self::fromJson($json, pretty: false, canonical: true);
    }

    /** Unseeded XXH3-128 of the canonical RON bytes, as 32 lowercase hex digits. */
    public static function canonicalHash(string $json): string {
        return hash('xxh128', self::canonicalRon($json));
    }

    /** RFC 8785 (JCS) canonical JSON. Distinct from compact JSON: numbers are normalized. */
    public static function canonicalJson(string $json): string {
        return Rfc8785::canonicalize($json);
    }
}
