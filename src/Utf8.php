<?php

declare(strict_types=1);

namespace Mbolli\Ron;

/**
 * Minimal UTF-8 helpers shared by the scanner, JSON writer and RON renderer.
 */
final class Utf8 {
    /**
     * Decode the rune starting at $pos. Invalid sequences yield [0xFFFD, 1],
     * matching Go's utf8.DecodeRune.
     *
     * @return array{0: int, 1: int} codepoint and byte length
     */
    public static function decodeRune(string $src, int $pos, int $len): array {
        $b0 = \ord($src[$pos]);
        if ($b0 < 0x80) {
            return [$b0, 1];
        }
        if ($b0 < 0xC0) {
            return [0xFFFD, 1];
        }
        if ($b0 < 0xE0) {
            if ($pos + 1 >= $len) {
                return [0xFFFD, 1];
            }
            $b1 = \ord($src[$pos + 1]);
            if (($b1 & 0xC0) !== 0x80) {
                return [0xFFFD, 1];
            }

            return [(($b0 & 0x1F) << 6) | ($b1 & 0x3F), 2];
        }
        if ($b0 < 0xF0) {
            if ($pos + 2 >= $len) {
                return [0xFFFD, 1];
            }
            $b1 = \ord($src[$pos + 1]);
            $b2 = \ord($src[$pos + 2]);
            if (($b1 & 0xC0) !== 0x80 || ($b2 & 0xC0) !== 0x80) {
                return [0xFFFD, 1];
            }

            return [(($b0 & 0x0F) << 12) | (($b1 & 0x3F) << 6) | ($b2 & 0x3F), 3];
        }
        if ($pos + 3 >= $len) {
            return [0xFFFD, 1];
        }
        $b1 = \ord($src[$pos + 1]);
        $b2 = \ord($src[$pos + 2]);
        $b3 = \ord($src[$pos + 3]);
        if (($b1 & 0xC0) !== 0x80 || ($b2 & 0xC0) !== 0x80 || ($b3 & 0xC0) !== 0x80) {
            return [0xFFFD, 1];
        }

        return [(($b0 & 0x07) << 18) | (($b1 & 0x3F) << 12) | (($b2 & 0x3F) << 6) | ($b3 & 0x3F), 4];
    }

    /** Unicode White_Space code points at or above 0x80 (ASCII handled separately). */
    public static function isSpaceAbove(int $r): bool {
        return match (true) {
            $r === 0x85, $r === 0xA0, $r === 0x1680 => true,
            $r >= 0x2000 && $r <= 0x200A => true,
            $r === 0x2028, $r === 0x2029, $r === 0x202F, $r === 0x205F, $r === 0x3000 => true,
            default => false,
        };
    }
}
