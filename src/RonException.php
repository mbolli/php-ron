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
    /**
     * Exception code marking a violation of the canonical contract rather than a
     * syntax error. Speculative parses (root-object elision) swallow syntax errors and
     * retry, but must let a canonical violation through so the real cause is reported.
     */
    public const int CANONICAL_VIOLATION = 1;

    public static function at(string $message, int $pos): self {
        return new self(\sprintf('ron: %s at byte %d', $message, $pos));
    }

    public static function canonical(string $message): self {
        return new self('ron: ' . $message, self::CANONICAL_VIOLATION);
    }

    public function isCanonicalViolation(): bool {
        return $this->getCode() === self::CANONICAL_VIOLATION;
    }
}
