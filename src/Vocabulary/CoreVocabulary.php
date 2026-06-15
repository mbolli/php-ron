<?php

declare(strict_types=1);

namespace Mbolli\Ron\Vocabulary;

use Mbolli\Ron\Value\RonNumber;

/**
 * Core typed vocabulary: identifiers, references, and primitive lexical types.
 *
 * Payload contracts are validation-only (the value model is preserved verbatim so
 * round-trips stay lossless); rules mirror ron-go's vocabulary_core.go.
 */
final class CoreVocabulary {
    public const string URI = 'https://ron.dev/vocab/core/v1';

    private const int SHA256_HEX_LEN = 64;

    /** @return array<string, \Closure(mixed, VocabularyValidator): mixed> */
    public static function validators(): array {
        return [
            '#uid' => static fn (mixed $p, VocabularyValidator $v): mixed => self::uid($p),
            '#url' => static fn (mixed $p, VocabularyValidator $v): mixed => self::url($p),
            '#dec' => static fn (mixed $p, VocabularyValidator $v): mixed => self::dec($p),
            '#b64' => static fn (mixed $p, VocabularyValidator $v): mixed => self::b64($p),
            '#sha256' => static fn (mixed $p, VocabularyValidator $v): mixed => self::sha256($p),
            '#' => static fn (mixed $p, VocabularyValidator $v): mixed => self::entityRef($p),
            '#tag' => static fn (mixed $p, VocabularyValidator $v): mixed => self::opaqueTag($p, $v),
        ];
    }

    private static function uid(mixed $payload): string {
        if (!\is_string($payload) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $payload) !== 1) {
            Payload::reject('#uid');
        }

        return $payload;
    }

    private static function url(mixed $payload): string {
        if (
            !\is_string($payload) || $payload === ''
            || strpbrk($payload, " \t\r\n") !== false
            || preg_match('~^[a-zA-Z][a-zA-Z0-9+.\-]*:~', $payload) !== 1
            || parse_url($payload) === false
        ) {
            Payload::reject('#url');
        }

        return $payload;
    }

    private static function dec(mixed $payload): string {
        if (!\is_string($payload) || $payload === '') {
            Payload::reject('#dec');
        }
        $len = \strlen($payload);
        $pos = 0;
        $negative = false;
        if ($payload[$pos] === '-') {
            $negative = true;
            ++$pos;
            if ($pos === $len) {
                Payload::reject('#dec');
            }
        }
        if ($payload[$pos] === '0') {
            ++$pos;
            if ($negative && $pos === $len) {
                Payload::reject('#dec'); // -0 is not canonical
            }
        } else {
            if ($payload[$pos] < '1' || $payload[$pos] > '9') {
                Payload::reject('#dec');
            }
            while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                ++$pos;
            }
        }
        if ($pos < $len) {
            if ($payload[$pos] !== '.') {
                Payload::reject('#dec');
            }
            ++$pos;
            $fractionStart = $pos;
            while ($pos < $len && $payload[$pos] >= '0' && $payload[$pos] <= '9') {
                ++$pos;
            }
            if ($pos !== $len || $pos === $fractionStart || $payload[$len - 1] === '0') {
                Payload::reject('#dec'); // trailing data, empty fraction, or redundant trailing zero
            }
        }

        return $payload;
    }

    private static function b64(mixed $payload): string {
        if (
            !\is_string($payload)
            || str_contains($payload, '=')
            || preg_match('~^[A-Za-z0-9_-]*$~', $payload) !== 1
            || \strlen($payload) % 4 === 1
            || base64_decode(strtr($payload, '-_', '+/'), true) === false
        ) {
            Payload::reject('#b64');
        }

        return $payload;
    }

    private static function sha256(mixed $payload): string {
        if (!\is_string($payload) || \strlen($payload) !== self::SHA256_HEX_LEN || preg_match('/^[0-9a-f]+$/', $payload) !== 1) {
            Payload::reject('#sha256');
        }

        return $payload;
    }

    private static function entityRef(mixed $payload): mixed {
        if (\is_string($payload)) {
            return $payload;
        }
        if ($payload instanceof RonNumber && Payload::isCanonicalInt($payload->text)) {
            return $payload;
        }
        Payload::reject('#');
    }

    private static function opaqueTag(mixed $payload, VocabularyValidator $validator): mixed {
        if (!\is_array($payload) || \count($payload) !== 2) {
            Payload::reject('#tag');
        }
        // The second element is an arbitrary value; recurse so nested enabled tags
        // inside it still validate. The first element is an implementation-defined id.
        $payload[1] = $validator->validate($payload[1]);

        return $payload;
    }
}
