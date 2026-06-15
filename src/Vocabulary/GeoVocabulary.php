<?php

declare(strict_types=1);

namespace Mbolli\Ron\Vocabulary;

use Mbolli\Ron\Value\RonObject;

/**
 * Geo typed vocabulary: `#geo` as an RFC 7946 GeoJSON object.
 *
 * Not present in ron-go yet; rules follow docs/vocabularies.md. Validates the
 * GeoJSON `type` and the coordinate nesting per type; foreign members and
 * `Feature.properties` are preserved opaquely (typed values inside properties stay
 * ordinary objects unless another enabled vocabulary interprets them).
 */
final class GeoVocabulary {
    public const string URI = 'https://ron.dev/vocab/geo/v1';

    private const array TYPES = [
        'Point', 'MultiPoint', 'LineString', 'MultiLineString', 'Polygon',
        'MultiPolygon', 'GeometryCollection', 'Feature', 'FeatureCollection',
    ];

    /** @return array<string, \Closure(mixed, VocabularyValidator): mixed> */
    public static function validators(): array {
        return [
            '#geo' => static function (mixed $p, VocabularyValidator $v): mixed {
                self::object($p);

                return $p;
            },
        ];
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

    /** A single coordinate position: at least two finite numbers. */
    private static function position(mixed $value): void {
        if (!\is_array($value) || \count($value) < 2) {
            Payload::reject('#geo');
        }
        foreach ($value as $component) {
            if (!Payload::isFloat($component)) {
                Payload::reject('#geo');
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
