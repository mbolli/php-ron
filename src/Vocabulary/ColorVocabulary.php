<?php

declare(strict_types=1);

namespace Mbolli\Ron\Vocabulary;

use Mbolli\Ron\Value\RonNumber;

/**
 * Color typed vocabulary: `#clr` as a color-space-discriminated tuple.
 *
 * Per docs/vocabularies.md, the payload is `[space, components...]`. Each supported
 * space carries three components; the alpha variants append a fourth alpha channel
 * normalized to [0, 1]. Components are finite numbers; channel-specific ranges
 * beyond alpha are not enforced (ron-go does not either). Not built in ron-go yet.
 */
final class ColorVocabulary {
    public const string URI = 'https://ron.dev/vocab/color/v1';

    /** All supported color spaces (base + alpha variants). */
    private const array SPACES = [
        'rgb', 'rgba', 'hsl', 'hsla', 'hsv', 'hsva', 'hwb', 'hwba',
        'lab', 'laba', 'lch', 'lcha', 'oklab', 'oklaba', 'oklch', 'oklcha',
        'xyz', 'xyza',
    ];

    /** Spaces whose tuple ends with a normalized [0, 1] alpha channel. */
    private const array ALPHA_SPACES = [
        'rgba', 'hsla', 'hsva', 'hwba', 'laba', 'lcha', 'oklaba', 'oklcha', 'xyza',
    ];

    /** @return array<string, \Closure(mixed, VocabularyValidator): mixed> */
    public static function validators(): array {
        return [
            '#clr' => static fn (mixed $p, VocabularyValidator $v): mixed => self::color($p),
        ];
    }

    /**
     * @return array<mixed>
     */
    private static function color(mixed $payload): array {
        if (!\is_array($payload) || $payload === []) {
            Payload::reject('#clr');
        }
        $space = $payload[0];
        if (!\is_string($space) || !\in_array($space, self::SPACES, true)) {
            Payload::reject('#clr');
        }
        // Three colour components, plus an alpha channel for the alpha variants.
        $hasAlpha = \in_array($space, self::ALPHA_SPACES, true);
        if (\count($payload) !== ($hasAlpha ? 5 : 4)) {
            Payload::reject('#clr');
        }
        for ($i = 1, $n = \count($payload); $i < $n; ++$i) {
            if (!Payload::isFloat($payload[$i])) {
                Payload::reject('#clr');
            }
        }
        if ($hasAlpha) {
            $alpha = $payload[\count($payload) - 1];
            if (!$alpha instanceof RonNumber || (float) $alpha->text < 0.0 || (float) $alpha->text > 1.0) {
                Payload::reject('#clr');
            }
        }

        return $payload;
    }
}
