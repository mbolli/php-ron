<?php

declare(strict_types=1);

namespace Mbolli\Ron\Value;

use Mbolli\Ron\RonTokenizer;

/**
 * The lexical role of a RON token, as produced by {@see RonTokenizer}.
 *
 * Roles carry both the lexical type and the syntactic position (keys are reported
 * separately from string values) so tooling such as syntax highlighters can colour
 * them without re-deriving RON's key/value context.
 */
enum RonTokenKind: string {
    /** A structural delimiter: {@code {}, } }, [ or ]. */
    case Punctuation = 'punctuation';

    /** An object key (bare, quoted, or comma-prefixed), root-elided or braced. */
    case Key = 'key';

    /** A string value (bare, quoted, apostrophe, or comma-prefixed). */
    case String = 'string';

    /** A numeric value (matches the JSON number grammar). */
    case Number = 'number';

    /** The {@code true} or {@code false} literal. */
    case Bool = 'bool';

    /** The {@code null} literal. */
    case Null = 'null';
}
