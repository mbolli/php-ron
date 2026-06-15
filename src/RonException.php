<?php

declare(strict_types=1);

namespace Mbolli\Ron;

/**
 * Raised for any invalid RON or JSON input encountered during conversion.
 *
 * The conformance corpus only checks that invalid input fails; exact error
 * strings are not asserted, so a single exception type is sufficient.
 */
final class RonException extends \RuntimeException {
    public static function at(string $message, int $pos): self {
        return new self(\sprintf('ron: %s at byte %d', $message, $pos));
    }
}
