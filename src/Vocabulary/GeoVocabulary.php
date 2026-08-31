<?php

declare(strict_types=1);

namespace Mbolli\Ron\Vocabulary;

use Mbolli\Ron\Value\RonNumber;
use Mbolli\Ron\Value\RonObject;

/**
 * Geo typed vocabulary: `#geo` (RFC 7946 GeoJSON) and `#topo` (TopoJSON topology).
 *
 * Not present in ron-go yet; rules follow docs/vocabularies.md and the corpus JSON
 * Schemas. Validates the `type` and the coordinate/arc nesting per type; foreign
 * members and `Feature.properties` are preserved opaquely (typed values inside
 * properties stay ordinary objects unless another enabled vocabulary interprets them).
 *
 * `#topo` is validated but never expanded: shared arcs stay indexed, negative
 * (ones-complement) indexes stay unreversed, and delta/quantized coordinates keep
 * their encoding, so canonical RON preserves the topology as written.
 */
final class GeoVocabulary {
    public const string URI = 'https://ron.dev/vocab/geo/v1';

    private const array TYPES = [
        'Point', 'MultiPoint', 'LineString', 'MultiLineString', 'Polygon',
        'MultiPolygon', 'GeometryCollection', 'Feature', 'FeatureCollection',
    ];

    /** Arc index nesting per TopoJSON geometry type: LineString 1, Polygon 2, etc. */
    private const array TOPO_ARC_DEPTH = [
        'LineString' => 1,
        'MultiLineString' => 2,
        'Polygon' => 2,
        'MultiPolygon' => 3,
    ];

    /** @return array<string, \Closure(mixed, VocabularyValidator): mixed> */
    public static function validators(): array {
        return [
            '#geo' => static function (mixed $p, VocabularyValidator $v): mixed {
                self::object($p);

                return $p;
            },
            '#topo' => static function (mixed $p, VocabularyValidator $v): mixed {
                self::topology($p);

                return $p;
            },
        ];
    }

    private static function topology(mixed $value): void {
        $members = self::membersOf($value, '#topo');
        if (($members['type'] ?? null) !== 'Topology') {
            Payload::reject('#topo');
        }

        $objects = $members['objects'] ?? null;
        if (!$objects instanceof RonObject) {
            Payload::reject('#topo');
        }
        foreach ($objects->members() as [, $geometry]) {
            self::topologyGeometry($geometry);
        }

        $arcs = $members['arcs'] ?? null;
        if (!\is_array($arcs)) {
            Payload::reject('#topo');
        }
        foreach ($arcs as $arc) {
            // An arc is a polyline: two or more positions.
            if (!\is_array($arc) || \count($arc) < 2) {
                Payload::reject('#topo');
            }
            foreach ($arc as $position) {
                self::position($position, '#topo');
            }
        }

        // array_key_exists, not isset: an explicit null transform is a schema violation,
        // not an absent one.
        if (\array_key_exists('transform', $members)) {
            $transform = self::membersOf($members['transform'], '#topo');
            foreach (['scale', 'translate'] as $key) {
                $pair = $transform[$key] ?? null;
                if (!\is_array($pair) || \count($pair) !== 2) {
                    Payload::reject('#topo');
                }
                foreach ($pair as $component) {
                    if (!Payload::isFloat($component)) {
                        Payload::reject('#topo');
                    }
                }
            }
        }
        self::bbox($members);
    }

    private static function topologyGeometry(mixed $value): void {
        $members = self::membersOf($value, '#topo');
        $type = $members['type'] ?? null;
        if (!\is_string($type)) {
            Payload::reject('#topo');
        }
        self::bbox($members);

        // Point and MultiPoint carry positions; every other type references arcs.
        if ($type === 'Point') {
            self::position($members['coordinates'] ?? null, '#topo');

            return;
        }
        if ($type === 'MultiPoint') {
            $coordinates = $members['coordinates'] ?? null;
            if (!\is_array($coordinates)) {
                Payload::reject('#topo');
            }
            foreach ($coordinates as $position) {
                self::position($position, '#topo');
            }

            return;
        }
        if ($type === 'GeometryCollection') {
            $geometries = $members['geometries'] ?? null;
            if (!\is_array($geometries)) {
                Payload::reject('#topo');
            }
            foreach ($geometries as $geometry) {
                self::topologyGeometry($geometry);
            }

            return;
        }

        $depth = self::TOPO_ARC_DEPTH[$type] ?? null;
        if ($depth === null) {
            Payload::reject('#topo');
        }
        self::arcIndexes($members['arcs'] ?? null, $depth);
    }

    /** A nested list of arc indexes; a negative index means the arc is reversed. */
    private static function arcIndexes(mixed $value, int $depth): void {
        if (!\is_array($value)) {
            Payload::reject('#topo');
        }
        foreach ($value as $element) {
            if ($depth > 1) {
                self::arcIndexes($element, $depth - 1);

                continue;
            }
            if (!$element instanceof RonNumber || !Payload::isCanonicalInt($element->text) || !self::isInt32($element->text)) {
                Payload::reject('#topo');
            }
        }
    }

    private static function isInt32(string $text): bool {
        $value = (int) $text;

        return $value >= -2147483648 && $value <= 2147483647;
    }

    /**
     * An optional bounding box: absent, or at least four finite numbers. Takes the
     * member map rather than the value so an explicit `bbox null` is rejected instead
     * of read as "not present".
     *
     * @param array<string, mixed> $members
     */
    private static function bbox(array $members): void {
        if (!\array_key_exists('bbox', $members)) {
            return;
        }
        $value = $members['bbox'];
        if (!\is_array($value) || \count($value) < 4) {
            Payload::reject('#topo');
        }
        foreach ($value as $component) {
            if (!Payload::isFloat($component)) {
                Payload::reject('#topo');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function membersOf(mixed $value, string $tag): array {
        if (!$value instanceof RonObject) {
            Payload::reject($tag);
        }
        $members = [];
        foreach ($value->members() as [$key, $member]) {
            $members[$key] = $member;
        }

        return $members;
    }

    private static function object(mixed $value): void {
        if (!$value instanceof RonObject) {
            Payload::reject('#geo');
        }
        $members = [];
        foreach ($value->members() as [$key, $member]) {
            $members[$key] = $member;
        }
        $type = $members['type'] ?? null;
        if (!\is_string($type) || !\in_array($type, self::TYPES, true)) {
            Payload::reject('#geo');
        }

        switch ($type) {
            case 'Point':
                self::position($members['coordinates'] ?? null);

                break;

            case 'MultiPoint':
            case 'LineString':
                self::positionArray($members['coordinates'] ?? null, 1);

                break;

            case 'MultiLineString':
            case 'Polygon':
                self::positionArray($members['coordinates'] ?? null, 2);

                break;

            case 'MultiPolygon':
                self::positionArray($members['coordinates'] ?? null, 3);

                break;

            case 'GeometryCollection':
                $geometries = $members['geometries'] ?? null;
                if (!\is_array($geometries)) {
                    Payload::reject('#geo');
                }
                foreach ($geometries as $geometry) {
                    self::object($geometry);
                }

                break;

            case 'Feature':
                $geometry = $members['geometry'] ?? null;
                if ($geometry !== null) {
                    self::object($geometry);
                }

                break;

            case 'FeatureCollection':
                $features = $members['features'] ?? null;
                if (!\is_array($features)) {
                    Payload::reject('#geo');
                }
                foreach ($features as $feature) {
                    self::object($feature);
                }

                break;
        }
    }

    /**
     * A single coordinate position: at least two finite numbers. GeoJSON allows an
     * optional third (altitude/elevation) element; TopoJSON allows any extra
     * dimensions, so neither form caps the length here.
     */
    private static function position(mixed $value, string $tag = '#geo'): void {
        if (!\is_array($value) || \count($value) < 2) {
            Payload::reject($tag);
        }
        foreach ($value as $component) {
            if (!Payload::isFloat($component)) {
                Payload::reject($tag);
            }
        }
    }

    /** A position array nested $depth levels deep (1 = list of positions, etc.). */
    private static function positionArray(mixed $value, int $depth): void {
        if (!\is_array($value)) {
            Payload::reject('#geo');
        }
        foreach ($value as $element) {
            if ($depth <= 1) {
                self::position($element);
            } else {
                self::positionArray($element, $depth - 1);
            }
        }
    }
}
