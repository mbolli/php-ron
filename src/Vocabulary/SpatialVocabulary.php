<?php

declare(strict_types=1);

namespace Mbolli\Ron\Vocabulary;

use Mbolli\Ron\Value\MultilineList;
use Mbolli\Ron\Value\RonNumber;
use Mbolli\Ron\Value\RonObject;

/**
 * Spatial typed vocabulary: geospatial points, geometry primitives, and voxels.
 *
 * Mirrors ron-go's vocabulary_spatial.go. Coordinate tuples are fixed-arity float
 * arrays. The `#vox` sparse voxel set requires dimensions/origin/cellSize/cells and
 * forces its non-empty `cells` list multiline ({@see MultilineList}). Voxel
 * origin/cellSize are validated structurally as `{#vN [...]}` shapes, independent of
 * whether the math vocabulary is enabled.
 */
final class SpatialVocabulary {
    public const string URI = 'https://ron.dev/vocab/spatial/v1';

    /** @return array<string, \Closure(mixed, VocabularyValidator): mixed> */
    public static function validators(): array {
        return [
            '#lla' => static fn (mixed $p, VocabularyValidator $v): mixed => self::tuple($p, 3, '#lla'),
            '#sph' => static fn (mixed $p, VocabularyValidator $v): mixed => self::tuple($p, 3, '#sph'),
            '#cyl' => static fn (mixed $p, VocabularyValidator $v): mixed => self::tuple($p, 3, '#cyl'),
            '#bx2' => static fn (mixed $p, VocabularyValidator $v): mixed => self::tuples($p, 2, 2, '#bx2'),
            '#bx3' => static fn (mixed $p, VocabularyValidator $v): mixed => self::tuples($p, 2, 3, '#bx3'),
            '#spr' => static fn (mixed $p, VocabularyValidator $v): mixed => self::centerRadius($p, '#spr'),
            '#pln' => static fn (mixed $p, VocabularyValidator $v): mixed => self::plane($p, '#pln'),
            '#ray' => static fn (mixed $p, VocabularyValidator $v): mixed => self::tuples($p, 2, 3, '#ray'),
            '#ln2' => static fn (mixed $p, VocabularyValidator $v): mixed => self::tuples($p, 2, 2, '#ln2'),
            '#ln3' => static fn (mixed $p, VocabularyValidator $v): mixed => self::tuples($p, 2, 3, '#ln3'),
            '#tri' => static fn (mixed $p, VocabularyValidator $v): mixed => self::tuples($p, 3, 3, '#tri'),
            '#fru' => static fn (mixed $p, VocabularyValidator $v): mixed => self::frustum($p),
            '#sh3' => static fn (mixed $p, VocabularyValidator $v): mixed => self::tuples($p, 9, 3, '#sh3'),
            '#vox' => static fn (mixed $p, VocabularyValidator $v): mixed => self::voxelSet($p, $v),
        ];
    }

    /**
     * @return array<mixed>
     */
    private static function tuple(mixed $payload, int $size, string $tag): array {
        if (!\is_array($payload) || \count($payload) !== $size) {
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
    private static function tuples(mixed $payload, int $count, int $size, string $tag): array {
        if (!\is_array($payload) || \count($payload) !== $count) {
            Payload::reject($tag);
        }
        foreach ($payload as $element) {
            self::tuple($element, $size, $tag);
        }

        return $payload;
    }

    /**
     * @return array<mixed>
     */
    private static function centerRadius(mixed $payload, string $tag): array {
        if (!\is_array($payload) || \count($payload) !== 2) {
            Payload::reject($tag);
        }
        self::tuple($payload[0], 3, $tag);
        if (!Payload::isFloat($payload[1])) {
            Payload::reject($tag);
        }

        return $payload;
    }

    /**
     * @return array<mixed>
     */
    private static function plane(mixed $payload, string $tag): array {
        if (!\is_array($payload) || \count($payload) !== 2) {
            Payload::reject($tag);
        }
        self::tuple($payload[0], 3, $tag);
        if (!Payload::isFloat($payload[1])) {
            Payload::reject($tag);
        }

        return $payload;
    }

    /**
     * @return array<mixed>
     */
    private static function frustum(mixed $payload): array {
        if (!\is_array($payload) || \count($payload) !== 6) {
            Payload::reject('#fru');
        }
        foreach ($payload as $plane) {
            self::plane($plane, '#fru');
        }

        return $payload;
    }

    private static function voxelSet(mixed $payload, VocabularyValidator $validator): RonObject {
        if (!$payload instanceof RonObject) {
            Payload::reject('#vox');
        }
        $members = [];
        foreach ($payload->members() as [$key, $value]) {
            $members[$key] = $value;
        }
        foreach (['dimensions', 'origin', 'cellSize', 'cells'] as $required) {
            if (!\array_key_exists($required, $members)) {
                Payload::reject('#vox');
            }
        }

        $dimensions = $members['dimensions'];
        if (!$dimensions instanceof RonNumber || !Payload::isInt64($dimensions->text) || (int) $dimensions->text <= 0) {
            Payload::reject('#vox');
        }
        $dims = (int) $dimensions->text;

        self::voxelVector($members['origin'], $dims);
        self::voxelVector($members['cellSize'], $dims);

        $cells = $members['cells'];
        if (!\is_array($cells)) {
            Payload::reject('#vox');
        }
        $validatedCells = [];
        foreach ($cells as $cell) {
            if (!\is_array($cell) || \count($cell) !== 2) {
                Payload::reject('#vox');
            }
            $coordinate = $cell[0];
            if (!\is_array($coordinate) || \count($coordinate) !== $dims) {
                Payload::reject('#vox');
            }
            foreach ($coordinate as $component) {
                if (!Payload::isInt64Number($component)) {
                    Payload::reject('#vox');
                }
            }
            // Cell values may carry other enabled tags (e.g. a #clr color).
            $validatedCells[] = [$coordinate, $validator->validate($cell[1])];
        }

        // Non-empty cells render one-per-line regardless of size (ron-go multilineArray).
        $cellsValue = $validatedCells === [] ? $validatedCells : new MultilineList($validatedCells);

        // Rebuild so the value model stays a clean list-backed RonObject.
        $result = new RonObject();
        foreach ($payload->members() as [$key, $value]) {
            $result->set($key, $key === 'cells' ? $cellsValue : $value);
        }

        return $result;
    }

    private static function voxelVector(mixed $value, int $dims): void {
        if (!$value instanceof RonObject || $value->count() !== 1 || $value->keys[0] !== '#vN') {
            Payload::reject('#vox');
        }
        $vector = $value->values[0];
        if (!\is_array($vector) || \count($vector) !== $dims) {
            Payload::reject('#vox');
        }
        foreach ($vector as $component) {
            if (!Payload::isFloat($component)) {
                Payload::reject('#vox');
            }
        }
    }
}
