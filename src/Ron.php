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
