<?php

declare(strict_types=1);

namespace Mbolli\Ron\Value;

/**
 * A JSON/RON number carried as its verbatim source text.
 *
 * RON and the normal JSON renderer preserve number text exactly (large integers,
 * exponent form, capital E). Keeping the text distinguishes a number from a string
 * in the value model without forcing it through a binary float.
 */
final class RonNumber implements \Stringable {
    public function __construct(public readonly string $text) {}

    public function __toString(): string {
        return $this->text;
    }
}
