<?php

declare(strict_types=1);

namespace Mbolli\Ron;

use Mbolli\Ron\Value\RonNumber;
use Mbolli\Ron\Value\RonObject;

/**
 * Converts arbitrary PHP values into the RON value model consumed by RonRenderer.
 *
 * Covers the same value space as json_encode: null, bool, string, int, float,
 * lists, maps (associative arrays and objects/stdClass), and JsonSerializable.
 * Non-finite floats, resources and other unencodable types are rejected, and a
 * nesting-depth limit guards against runaway recursion (e.g. circular objects).
 */
final class Encoder {
    /** Matches json_encode's default nesting depth. */
    private const int DEFAULT_DEPTH = 512;

    public static function toModel(mixed $value, int $depth = self::DEFAULT_DEPTH): mixed {
        if ($depth < 0) {
            throw new RonException('ron: maximum encoding depth exceeded');
        }

        return match (true) {
            $value === null, \is_bool($value), \is_string($value) => $value,
            $value instanceof RonNumber, $value instanceof RonObject => $value,
            \is_int($value) => new RonNumber((string) $value),
            \is_float($value) => new RonNumber(self::floatToText($value)),
            \is_array($value) => self::arrayToModel($value, $depth),
            $value instanceof \JsonSerializable => self::toModel($value->jsonSerialize(), $depth - 1),
            \is_object($value) => self::objectToModel($value, $depth),
            default => throw new RonException('ron: cannot encode value of type ' . get_debug_type($value)),
        };
    }

    /** @param array<array-key, mixed> $value */
    private static function arrayToModel(array $value, int $depth): mixed {
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = self::toModel($item, $depth - 1);
            }

            return $out;
        }

        $object = new RonObject();
        foreach ($value as $key => $item) {
            $object->set((string) $key, self::toModel($item, $depth - 1));
        }

        return $object;
    }

    private static function objectToModel(object $value, int $depth): RonObject {
        $object = new RonObject();
        // get_object_vars() from outside the class returns only public properties,
        // matching json_encode's treatment of plain objects.
        foreach (get_object_vars($value) as $key => $item) {
            $object->set($key, self::toModel($item, $depth - 1));
        }

        return $object;
    }

    private static function floatToText(float $value): string {
        if (!is_finite($value)) {
            throw new RonException('ron: cannot encode a non-finite float (INF or NAN)');
        }
        // Shortest decimal that round-trips, like the JSON number it stands in for.
        for ($precision = 1; $precision <= 17; ++$precision) {
            $text = \sprintf('%.' . $precision . 'g', $value);
            if ((float) $text === $value) {
                return $text;
            }
        }

        return \sprintf('%.17g', $value);
    }
}
