<?php

declare(strict_types=1);

namespace Mbolli\Ron;

/**
 * RFC 8785 object key ordering: compare keys as UTF-16 code unit arrays.
 *
 * Byte-wise strcmp of the UTF-8 strings already equals UTF-16 code-unit order for
 * every key whose characters are all in the BMP (U+0000..U+FFFF): UTF-8 byte order
 * equals code-point order, and BMP code points equal their single UTF-16 unit. The
 * two orders only diverge when a supplementary character (U+10000..U+10FFFF, encoded
 * as a 4-byte UTF-8 sequence with a lead byte >= 0xF0) is involved, because UTF-16
 * encodes it as a surrogate pair that sorts before U+E000..U+FFFF. So strcmp is used
 * directly unless a 4-byte sequence is present, in which case the keys are converted
 * to UTF-16BE (big-endian, so byte order equals code-unit order) and compared.
 */
final class Canonical {
    /** Lead bytes of 4-byte UTF-8 sequences (supplementary plane characters). */
    private const string SUPPLEMENTARY_LEADS = "\xF0\xF1\xF2\xF3\xF4\xF5\xF6\xF7";

    /**
     * Sort object members by canonical key order.
     *
     * Uses array_multisort with precomputed sort keys (a Schwartzian transform) so
     * the comparison runs in C instead of an O(n log n) PHP comparator. For BMP keys
     * the sort key is the raw UTF-8 string (byte order == UTF-16 order); supplementary
     * keys use their UTF-16BE encoding.
     *
     * @param list<array{0: string, 1: mixed}> $members
     *
     * @return list<array{0: string, 1: mixed}>
     */
    public static function sortMembers(array $members): array {
        if (\count($members) < 2) {
            return $members;
        }
        // The sort-key encoding must be consistent across all keys: UTF-8 and UTF-16BE
        // byte sequences are not comparable to each other. Only when some key contains
        // a supplementary character (where the two orders diverge) do all keys switch
        // to UTF-16BE; otherwise the raw UTF-8 keys already sort in UTF-16 order.
        $anySupplementary = false;
        foreach ($members as [$key]) {
            if (strcspn($key, self::SUPPLEMENTARY_LEADS) !== \strlen($key)) {
                $anySupplementary = true;

                break;
            }
        }
        $sortKeys = [];
        if ($anySupplementary) {
            foreach ($members as [$key]) {
                $sortKeys[] = (string) mb_convert_encoding($key, 'UTF-16BE', 'UTF-8');
            }
        } else {
            foreach ($members as [$key]) {
                $sortKeys[] = $key;
            }
        }
        array_multisort($sortKeys, SORT_STRING, $members);

        return $members;
    }

    /**
     * Sort parallel key/value lists in place by canonical key order, without
     * materialising [key, value] pairs. Same encoding rules as sortMembers().
     *
     * @param list<string> $keys
     * @param list<mixed>  $values
     */
    public static function sortKeyedValues(array &$keys, array &$values): void {
        if (\count($keys) < 2) {
            return;
        }
        foreach ($keys as $key) {
            if (strcspn($key, self::SUPPLEMENTARY_LEADS) !== \strlen($key)) {
                $sortKeys = [];
                foreach ($keys as $k) {
                    $sortKeys[] = (string) mb_convert_encoding($k, 'UTF-16BE', 'UTF-8');
                }
                array_multisort($sortKeys, SORT_STRING, $keys, $values);

                return;
            }
        }
        array_multisort($keys, SORT_STRING, $values);
    }
}
