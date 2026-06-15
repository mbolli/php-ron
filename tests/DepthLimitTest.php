<?php

declare(strict_types=1);

namespace Mbolli\Ron\Tests;

use Mbolli\Ron\Ron;
use Mbolli\Ron\RonException;
use PHPUnit\Framework\TestCase;

/**
 * The recursive parsers cap nesting depth so pathologically deep input throws a
 * catchable RonException instead of overflowing the stack (or hanging). The cap
 * is configurable per call and defaults to Ron::DEFAULT_MAX_DEPTH (512).
 */
final class DepthLimitTest extends TestCase {
    public function testFromJsonAcceptsAtLimitRejectsBeyond(): void {
        self::assertIsString(Ron::fromJson(self::nestedJson(5), maxDepth: 5));
        self::assertRejects(static fn () => Ron::fromJson(self::nestedJson(6), maxDepth: 5));
    }

    public function testToJsonAcceptsAtLimitRejectsBeyond(): void {
        self::assertIsString(Ron::toJson(self::nestedJson(5), maxDepth: 5));
        self::assertRejects(static fn () => Ron::toJson(self::nestedJson(6), maxDepth: 5));
    }

    public function testCanonicalJsonAcceptsAtLimitRejectsBeyond(): void {
        self::assertIsString(Ron::canonicalJson(self::nestedJson(5), maxDepth: 5));
        self::assertRejects(static fn () => Ron::canonicalJson(self::nestedJson(6), maxDepth: 5));
    }

    public function testDecodeAcceptsAtLimitRejectsBeyond(): void {
        self::assertIsArray(Ron::decode(self::nestedJson(5), maxDepth: 5));
        self::assertRejects(static fn () => Ron::decode(self::nestedJson(6), maxDepth: 5));
    }

    public function testEncodeRejectsDeeplyNestedArray(): void {
        $shallow = [];
        for ($i = 0; $i < 3; ++$i) {
            $shallow = [$shallow];
        }
        self::assertIsString(Ron::encode($shallow, maxDepth: 5));

        $deep = [];
        for ($i = 0; $i < 50; ++$i) {
            $deep = [$deep];
        }
        self::assertRejects(static fn () => Ron::encode($deep, maxDepth: 5));
    }

    /** All three parse paths must agree on the default boundary: 512 in, 513 out. */
    public function testDefaultDepthBoundaryAgreesAcrossPaths(): void {
        $atLimit = self::nestedJson(Ron::DEFAULT_MAX_DEPTH);
        $beyond = self::nestedJson(Ron::DEFAULT_MAX_DEPTH + 1);

        self::assertIsString(Ron::fromJson($atLimit));
        self::assertIsString(Ron::toJson($atLimit));
        self::assertIsString(Ron::canonicalJson($atLimit));

        self::assertRejects(static fn () => Ron::fromJson($beyond));
        self::assertRejects(static fn () => Ron::toJson($beyond));
        self::assertRejects(static fn () => Ron::canonicalJson($beyond));
    }

    /** n nested arrays: "[[[...]]]" with an empty array at the bottom. */
    private static function nestedJson(int $depth): string {
        return str_repeat('[', $depth) . str_repeat(']', $depth);
    }

    private static function assertRejects(\Closure $fn): void {
        try {
            $fn();
        } catch (RonException) {
            self::assertTrue(true);

            return;
        }
        self::fail('expected RonException for over-deep input');
    }
}
