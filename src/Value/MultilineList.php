<?php

declare(strict_types=1);

namespace Mbolli\Ron\Value;

/**
 * A list that always renders one element per line, never inline.
 *
 * Base RON arrays inline when they fit the byte budget. Some vocabulary-aware
 * renderings deliberately override that: the spatial `#vox` voxel set forces its
 * non-empty `cells` list multiline regardless of size (mirroring ron-go's
 * multilineArray), because cell data reads better stacked. The renderer treats
 * this like an ordinary list except that it skips the inline pass.
 *
 * @phpstan-type Element mixed
 */
final class MultilineList {
    /** @param list<mixed> $items */
    public function __construct(public readonly array $items) {}
}
