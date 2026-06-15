<?php

declare(strict_types=1);

namespace Mbolli\Ron\Value;

use Mbolli\Ron\RonTokenizer;

/**
 * A single lexical token over RON source, produced by {@see RonTokenizer}.
 *
 * Offset and length describe the verbatim source span (a quoted string's span
 * includes its surrounding quote runs), so consumers can slice the original input
 * with substr() without re-decoding.
 */
final class RonToken {
    public function __construct(
        public readonly int $offset,
        public readonly int $length,
        public readonly RonTokenKind $kind,
    ) {}
}
