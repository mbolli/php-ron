<?php

declare(strict_types=1);

namespace Mbolli\Ron\Vocabulary;

/**
 * A validator's explicit replacement payload.
 *
 * A bare `true`/`false` return from a validator is read as accept/reject, so it
 * cannot also mean "replace the payload with the boolean true/false". Returning
 * {@see VocabularyRegistry::replace()} disambiguates: the wrapped value becomes the
 * payload verbatim, booleans included.
 */
final class Replacement {
    public function __construct(public readonly mixed $value) {}
}
