<?php

declare(strict_types=1);

namespace Mbolli\Ron\Value;

/**
 * Insertion-ordered string-keyed object.
 *
 * Mirrors ron-go's orderedObject: duplicate keys keep the last value and move the
 * surviving member to the position of its last occurrence. A key->position index is
 * always maintained so set() is O(1) for the common (no-duplicate) case; the index
 * is only rebuilt on the rare duplicate.
 *
 * A dedicated class (rather than a PHP associative array) is required because PHP
 * coerces numeric string keys such as "123" to integers, which would corrupt RON
 * keys and key ordering. The internal index may use coerced keys, but the public
 * $keys list always preserves the original strings.
 *
 * @phpstan-type Member array{0: string, 1: mixed}
 */
final class RonObject {
    /** @var list<string> */
    public array $keys = [];

    /** @var list<mixed> */
    public array $values = [];

    /** @var array<array-key, int> */
    private array $index = [];

    public function set(string $key, mixed $value): void {
        $existing = $this->index[$key] ?? null;
        if ($existing !== null) {
            // Duplicate: drop the earlier occurrence so the last value wins at the
            // last position, then rebuild the (now shifted) index.
            array_splice($this->keys, $existing, 1);
            array_splice($this->values, $existing, 1);
            $this->index = [];
            $count = \count($this->keys);
            for ($i = 0; $i < $count; ++$i) {
                $this->index[$this->keys[$i]] = $i;
            }
        }

        $this->index[$key] = \count($this->keys);
        $this->keys[] = $key;
        $this->values[] = $value;
    }

    public function count(): int {
        return \count($this->keys);
    }

    /**
     * Returns [key, value] pairs in the order they should be rendered.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    public function members(): array {
        $out = [];
        $count = \count($this->keys);
        for ($i = 0; $i < $count; ++$i) {
            $out[] = [$this->keys[$i], $this->values[$i]];
        }

        return $out;
    }
}
