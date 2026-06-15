<?php

declare(strict_types=1);

namespace Mbolli\Ron\Vocabulary;

use Mbolli\Ron\RonException;

/**
 * Maps typed-value tags to the vocabulary that owns them and to a payload validator.
 *
 * {@see official()} builds the registry of the seven built-in vocabularies. Custom
 * vocabularies are added with {@see register()}: a stable URI plus a map of tag
 * names to validators. A validator receives the payload (and the active
 * {@see VocabularyValidator} for recursing into nested values) and returns `true`
 * to accept it, `false` to reject it, or any other value to replace it (a
 * transform). It may also throw {@see RonException} to reject with a message. To
 * replace the payload with a literal boolean, return {@see replace()}.
 */
final class VocabularyRegistry {
    public const string CORE_V1 = CoreVocabulary::URI;
    public const string TIME_V1 = TimeVocabulary::URI;
    public const string NETWORK_V1 = NetworkVocabulary::URI;
    public const string MATH_V1 = MathVocabulary::URI;
    public const string SPATIAL_V1 = SpatialVocabulary::URI;
    public const string COLOR_V1 = ColorVocabulary::URI;
    public const string GEO_V1 = GeoVocabulary::URI;

    /** @var array<string, true> */
    private array $uris = [];

    /** @var array<string, array{0: string, 1: \Closure}> tag => [owning uri, validator] */
    private array $tags = [];

    /** Registry of all seven built-in vocabularies. */
    public static function official(): self {
        $registry = new self();
        $registry->register(CoreVocabulary::URI, CoreVocabulary::validators());
        $registry->register(TimeVocabulary::URI, TimeVocabulary::validators());
        $registry->register(NetworkVocabulary::URI, NetworkVocabulary::validators());
        $registry->register(MathVocabulary::URI, MathVocabulary::validators());
        $registry->register(SpatialVocabulary::URI, SpatialVocabulary::validators());
        $registry->register(ColorVocabulary::URI, ColorVocabulary::validators());
        $registry->register(GeoVocabulary::URI, GeoVocabulary::validators());

        return $registry;
    }

    /**
     * Registers (or overrides) the tags owned by a vocabulary URI.
     *
     * Each validator returns `true` to accept the payload, `false` to reject it, or
     * any other value to replace it (a transform); it may also throw a RonException.
     *
     * @param array<string, callable(mixed, VocabularyValidator): mixed> $validators
     */
    public function register(string $uri, array $validators): void {
        $this->uris[$uri] = true;
        foreach ($validators as $tag => $validator) {
            $this->tags[$tag] = [$uri, \Closure::fromCallable($validator)];
        }
    }

    /**
     * Wraps a value so a validator replaces the payload with it even when it is a
     * boolean (a bare true/false return is read as accept/reject, not as a payload).
     */
    public static function replace(mixed $value): Replacement {
        return new Replacement($value);
    }

    public function knows(string $uri): bool {
        return isset($this->uris[$uri]);
    }

    /**
     * Rejects any enabled vocabulary URI the registry does not support (the
     * required-but-unknown profile case).
     *
     * @param list<string> $vocabularies
     */
    public function assertEnabled(array $vocabularies): void {
        foreach ($vocabularies as $uri) {
            if (!isset($this->uris[$uri])) {
                throw new RonException('ron: unsupported vocabulary: ' . $uri);
            }
        }
    }

    /**
     * Returns the validator for $tag when its owning vocabulary is enabled, else null.
     *
     * @param array<string, true> $enabled
     */
    public function validatorFor(string $tag, array $enabled): ?\Closure {
        $entry = $this->tags[$tag] ?? null;
        if ($entry === null || !isset($enabled[$entry[0]])) {
            return null;
        }

        return $entry[1];
    }
}
