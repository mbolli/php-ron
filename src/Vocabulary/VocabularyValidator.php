<?php

declare(strict_types=1);

namespace Mbolli\Ron\Vocabulary;

use Mbolli\Ron\RonException;
use Mbolli\Ron\Value\MultilineList;
use Mbolli\Ron\Value\RonObject;

/**
 * Walks a value model and validates the enabled typed values within it.
 *
 * A typed value is a single-member object whose key is a tag owned by an enabled
 * vocabulary. An object that carries such a tag but has more than one member is an
 * error (typed values must have exactly one member); objects without any enabled tag
 * are ordinary and are recursed into. Validation is in place: payloads may be
 * transformed (e.g. `#vox` cells become a {@see MultilineList}),
 * but everything else is preserved verbatim so round-trips stay lossless.
 */
final class VocabularyValidator {
    /** @var array<string, true> */
    private readonly array $enabled;

    /** @param list<string> $vocabularies enabled vocabulary URIs */
    public function __construct(
        private readonly VocabularyRegistry $registry,
        array $vocabularies,
    ) {
        $registry->assertEnabled($vocabularies);
        $enabled = [];
        foreach ($vocabularies as $uri) {
            $enabled[$uri] = true;
        }
        $this->enabled = $enabled;
    }

    public function validate(mixed $value): mixed {
        if (\is_array($value)) {
            foreach ($value as $index => $child) {
                $value[$index] = $this->validate($child);
            }

            return $value;
        }
        if ($value instanceof RonObject) {
            foreach ($value->keys as $key) {
                $validator = $this->registry->validatorFor($key, $this->enabled);
                if ($validator !== null) {
                    if ($value->count() !== 1) {
                        throw new RonException('ron: typed value must have exactly one member');
                    }
                    // A validator returns true to accept the payload as-is, false to reject it,
                    // or any other value to replace it (a transform, e.g. #vox -> MultilineList).
                    // A Replacement forces its wrapped value through even when it is a bool.
                    $result = $validator($value->values[0], $this);
                    if ($result instanceof Replacement) {
                        $value->values[0] = $result->value;
                    } elseif ($result === false) {
                        throw new RonException('ron: invalid ' . $key . ' payload');
                    } elseif ($result !== true) {
                        $value->values[0] = $result;
                    }

                    return $value;
                }
            }
            foreach ($value->values as $index => $child) {
                $value->values[$index] = $this->validate($child);
            }

            return $value;
        }

        return $value;
    }
}
