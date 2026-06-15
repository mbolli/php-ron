<?php

declare(strict_types=1);

namespace Mbolli\Ron\Vocabulary;

/**
 * Math typed vocabulary: scalars, vectors, quaternions, Euler angles, matrices.
 *
 * Mirrors ron-go's vocabulary_math.go. Fixed-width integers are canonical base-10
 * strings (so values outside the IEEE 754 safe range survive JSON tooling); floats
 * are finite JSON numbers; integer/float vectors are arrays with the documented arity.
 */
final class MathVocabulary {
    public const string URI = 'https://ron.dev/vocab/math/v1';

    private const array EULER_ORDERS = ['XYZ', 'YXZ', 'ZXY', 'ZYX', 'YZX', 'XZY'];

    /** @return array<string, \Closure(mixed, VocabularyValidator): mixed> */
    public static function validators(): array {
        return [
            '#i64' => static fn (mixed $p, VocabularyValidator $v): mixed => self::int64String($p),
            '#u64' => static fn (mixed $p, VocabularyValidator $v): mixed => self::uint64String($p),
            '#f64' => static fn (mixed $p, VocabularyValidator $v): mixed => self::float64($p),
            '#ivN' => static fn (mixed $p, VocabularyValidator $v): mixed => self::intVector($p, null, '#ivN'),
            '#vN' => static fn (mixed $p, VocabularyValidator $v): mixed => self::floatVector($p, null, '#vN'),
            '#iv2' => static fn (mixed $p, VocabularyValidator $v): mixed => self::intVector($p, 2, '#iv2'),
            '#iv3' => static fn (mixed $p, VocabularyValidator $v): mixed => self::intVector($p, 3, '#iv3'),
            '#iv4' => static fn (mixed $p, VocabularyValidator $v): mixed => self::intVector($p, 4, '#iv4'),
            '#f2v' => static fn (mixed $p, VocabularyValidator $v): mixed => self::floatVector($p, 2, '#f2v'),
            '#f3v' => static fn (mixed $p, VocabularyValidator $v): mixed => self::floatVector($p, 3, '#f3v'),
            '#f4v' => static fn (mixed $p, VocabularyValidator $v): mixed => self::floatVector($p, 4, '#f4v'),
            '#qat' => static fn (mixed $p, VocabularyValidator $v): mixed => self::floatVector($p, 4, '#qat'),
            '#eul' => static fn (mixed $p, VocabularyValidator $v): mixed => self::euler($p),
            '#m2x' => static fn (mixed $p, VocabularyValidator $v): mixed => self::floatVector($p, 4, '#m2x'),
            '#m3x' => static fn (mixed $p, VocabularyValidator $v): mixed => self::floatVector($p, 9, '#m3x'),
            '#m4x' => static fn (mixed $p, VocabularyValidator $v): mixed => self::floatVector($p, 16, '#m4x'),
        ];
    }

    private static function int64String(mixed $payload): string {
        if (!\is_string($payload) || !Payload::isInt64($payload)) {
            Payload::reject('#i64');
        }

        return $payload;
    }

    private static function uint64String(mixed $payload): string {
        if (!\is_string($payload) || !Payload::isUint64($payload)) {
            Payload::reject('#u64');
        }

        return $payload;
    }

    private static function float64(mixed $payload): mixed {
        if (!Payload::isFloat($payload)) {
            Payload::reject('#f64');
        }

        return $payload;
    }

    /**
     * @return array<mixed>
     */
    private static function intVector(mixed $payload, ?int $length, string $tag): array {
        if (!\is_array($payload) || ($length !== null && \count($payload) !== $length)) {
            Payload::reject($tag);
        }
        foreach ($payload as $element) {
            if (!Payload::isInt64Number($element)) {
                Payload::reject($tag);
            }
        }

        return $payload;
    }

    /**
     * @return array<mixed>
     */
    private static function floatVector(mixed $payload, ?int $length, string $tag): array {
        if (!\is_array($payload) || ($length !== null && \count($payload) !== $length)) {
            Payload::reject($tag);
        }
        foreach ($payload as $element) {
            if (!Payload::isFloat($element)) {
                Payload::reject($tag);
            }
        }

        return $payload;
    }

    /**
     * @return array<mixed>
     */
    private static function euler(mixed $payload): array {
        if (!\is_array($payload) || \count($payload) !== 4) {
            Payload::reject('#eul');
        }
        for ($i = 0; $i < 3; ++$i) {
            if (!Payload::isFloat($payload[$i])) {
                Payload::reject('#eul');
            }
        }
        if (!\is_string($payload[3]) || !\in_array($payload[3], self::EULER_ORDERS, true)) {
            Payload::reject('#eul');
        }

        return $payload;
    }
}
