<?php

declare(strict_types=1);

namespace Mbolli\Ron\Vocabulary;

/**
 * Network typed vocabulary: IPv4, IPv6, and CIDR prefixes.
 *
 * Mirrors ron-go's vocabulary_network.go: each payload must already be in its
 * canonical text form (re-serialization equals the input), and a CIDR prefix must
 * be masked (host bits zero). IPv6 canonicalization relies on the platform
 * inet_ntop, which produces RFC 5952-style compressed lowercase output.
 */
final class NetworkVocabulary {
    public const string URI = 'https://ron.dev/vocab/network/v1';

    /** @return array<string, \Closure(mixed, VocabularyValidator): mixed> */
    public static function validators(): array {
        return [
            '#ip4' => static fn (mixed $p, VocabularyValidator $v): mixed => self::ip4($p),
            '#ip6' => static fn (mixed $p, VocabularyValidator $v): mixed => self::ip6($p),
            '#cdr' => static fn (mixed $p, VocabularyValidator $v): mixed => self::cdr($p),
        ];
    }

    private static function ip4(mixed $payload): string {
        if (!\is_string($payload) || !self::isCanonicalIp4($payload)) {
            Payload::reject('#ip4');
        }

        return $payload;
    }

    private static function ip6(mixed $payload): string {
        if (!\is_string($payload) || str_contains($payload, '.')) {
            Payload::reject('#ip6'); // reject IPv4 and IPv4-mapped (::ffff:a.b.c.d) forms
        }
        $packed = @inet_pton($payload);
        if ($packed === false || \strlen($packed) !== 16 || inet_ntop($packed) !== $payload) {
            Payload::reject('#ip6');
        }

        return $payload;
    }

    private static function cdr(mixed $payload): string {
        if (!\is_string($payload)) {
            Payload::reject('#cdr');
        }
        $slash = strpos($payload, '/');
        if ($slash === false) {
            Payload::reject('#cdr');
        }
        $addr = substr($payload, 0, $slash);
        $prefixText = substr($payload, $slash + 1);
        if ($prefixText === '' || preg_match('/^(0|[1-9][0-9]*)$/', $prefixText) !== 1) {
            Payload::reject('#cdr');
        }
        $packed = @inet_pton($addr);
        if ($packed === false || inet_ntop($packed) !== $addr) {
            Payload::reject('#cdr');
        }
        $prefix = (int) $prefixText;
        if ($prefix > \strlen($packed) * 8 || !self::hostBitsZero($packed, $prefix)) {
            Payload::reject('#cdr');
        }

        return $payload;
    }

    private static function isCanonicalIp4(string $value): bool {
        $parts = explode('.', $value);
        if (\count($parts) !== 4) {
            return false;
        }
        foreach ($parts as $part) {
            if (preg_match('/^(0|[1-9][0-9]?[0-9]?)$/', $part) !== 1 || (int) $part > 255) {
                return false;
            }
        }

        return true;
    }

    private static function hostBitsZero(string $packed, int $prefix): bool {
        $bytes = \strlen($packed);
        for ($i = 0; $i < $bytes; ++$i) {
            $byte = \ord($packed[$i]);
            for ($bit = 0; $bit < 8; ++$bit) {
                if ($i * 8 + $bit >= $prefix && ($byte & (1 << (7 - $bit))) !== 0) {
                    return false;
                }
            }
        }

        return true;
    }
}
