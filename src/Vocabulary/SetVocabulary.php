<?php

declare(strict_types=1);

namespace Mbolli\Ron\Vocabulary;

use Mbolli\Ron\Rfc8785;
use Mbolli\Ron\Value\RonNumber;

/**
 * Set typed vocabulary: logical finite sets (port of ron-go's vocabulary_set.go).
 *
 * Unlike most vocabularies these validators are *transforms*: they normalize the
 * payload rather than only checking it, because the canonical form is part of the
 * tag's contract.
 *
 * - `#set` is a duplicate-free array sorted by each element's RFC 8785 canonical JSON
 *   bytes, so `{b 2 a 1}` and `{a 1 b 2}` are the same element. Identity is the canonical
 *   JSON of the *parsed* element, as the spec specifies -- not of a vocabulary-normalized
 *   one. A typed value nested inside a `#set` is therefore compared as written, so
 *   `{#bits [2 1]}` and `{#bits [[1 2]]}` are two distinct elements even though they
 *   denote the same bitset. {@see VocabularyValidator} stops recursing at a typed value,
 *   which is what ron-go does too, and the byte-for-byte parity matters more here than
 *   collapsing that case.
 * - `#bits` is a uint32 index set written as ascending, duplicate-free, non-overlapping
 *   and non-adjacent entries: singletons as numbers, runs as inclusive `[first, last]`
 *   ranges. Adjacent entries merge, so `[1 [3 4] 5 10]` normalizes to `[1 [3 5] 10]`.
 */
final class SetVocabulary {
    public const string URI = 'https://ron.dev/vocab/set/v1';

    private const int UINT32_MAX = 4294967295;

    /** @return array<string, \Closure(mixed, VocabularyValidator): mixed> */
    public static function validators(): array {
        return [
            '#set' => static fn (mixed $p, VocabularyValidator $v): mixed => self::set($p),
            '#bits' => static fn (mixed $p, VocabularyValidator $v): mixed => self::bits($p),
        ];
    }

    /**
     * @return list<mixed>
     */
    private static function set(mixed $payload): array {
        if (!\is_array($payload) || !array_is_list($payload)) {
            Payload::reject('#set');
        }

        /** @var list<array{0: string, 1: mixed}> $entries */
        $entries = [];
        $seen = [];
        foreach ($payload as $element) {
            $key = Rfc8785::canonicalizeValue($element);
            // PHP may coerce a numeric-looking key to int here, but it does so
            // consistently, and two different canonical JSON texts never land in the
            // same bucket, so membership stays exact.
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $entries[] = [$key, $element];
        }

        // Ordering is lexicographic over the canonical JSON UTF-8 *bytes* (Go compares
        // strings byte-wise), which is strcmp -- not the UTF-16 order used for keys.
        usort($entries, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return array_column($entries, 1);
    }

    /**
     * @return list<mixed>
     */
    private static function bits(mixed $payload): array {
        if (!\is_array($payload) || !array_is_list($payload)) {
            Payload::reject('#bits');
        }

        /** @var list<array{0: int, 1: int}> $ranges */
        $ranges = [];
        foreach ($payload as $entry) {
            if (\is_array($entry) && array_is_list($entry)) {
                if (\count($entry) !== 2) {
                    Payload::reject('#bits');
                }
                $first = self::endpoint($entry[0]);
                $last = self::endpoint($entry[1]);
                if ($first > $last) {
                    Payload::reject('#bits');
                }
                $ranges[] = [$first, $last];

                continue;
            }
            $index = self::endpoint($entry);
            $ranges[] = [$index, $index];
        }
        if ($ranges === []) {
            return [];
        }

        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        /** @var list<array{0: int, 1: int}> $merged */
        $merged = [];
        foreach ($ranges as [$first, $last]) {
            $tail = array_key_last($merged);
            // `first <= last + 1` merges adjacent runs as well as overlapping ones, so
            // the result has no two entries that could be written as one range.
            if ($tail !== null && $first <= $merged[$tail][1] + 1) {
                if ($last > $merged[$tail][1]) {
                    $merged[$tail][1] = $last;
                }

                continue;
            }
            $merged[] = [$first, $last];
        }

        $out = [];
        foreach ($merged as [$first, $last]) {
            $out[] = $first === $last
                ? new RonNumber((string) $first)
                : [new RonNumber((string) $first), new RonNumber((string) $last)];
        }

        return $out;
    }

    /**
     * A uint32 bit index. Validated on the number's source text so a fractional or
     * out-of-range value is rejected rather than silently truncated.
     */
    private static function endpoint(mixed $value): int {
        if (
            !$value instanceof RonNumber
            || !Payload::isCanonicalInt($value->text)
            || $value->text[0] === '-'
            || \strlen($value->text) > 10
        ) {
            Payload::reject('#bits');
        }
        $index = (int) $value->text;
        if ($index > self::UINT32_MAX) {
            Payload::reject('#bits');
        }

        return $index;
    }
}
