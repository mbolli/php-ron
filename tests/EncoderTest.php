<?php

declare(strict_types=1);

namespace Mbolli\Ron\Tests;

use Mbolli\Ron\Ron;
use Mbolli\Ron\RonException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the json_encode-style Ron::encode() / Ron::decode() API.
 */
final class EncoderTest extends TestCase {
    use ComparesJson;

    public function testEncodeScalars(): void {
        self::assertSame('null', Ron::encode(null, pretty: false));
        self::assertSame('true', Ron::encode(true, pretty: false));
        self::assertSame('false', Ron::encode(false, pretty: false));
        self::assertSame('42', Ron::encode(42, pretty: false));
        self::assertSame('hello', Ron::encode('hello', pretty: false));
        self::assertSame("'a b'", Ron::encode('a b', pretty: false));
    }

    public function testEncodeArraysAndObjectsAreCanonical(): void {
        self::assertSame('[1 2 3]', Ron::encode([1, 2, 3], pretty: false));
        self::assertSame('active true name Ada', Ron::encode(['name' => 'Ada', 'active' => true], pretty: false));
        self::assertSame("name Ada\n", Ron::encode(['name' => 'Ada']));
    }

    public function testEncodeStdClassAndJsonSerializable(): void {
        $object = new \stdClass();
        $object->b = 2;
        $object->a = 1;
        self::assertSame('a 1 b 2', Ron::encode($object, pretty: false));

        $serializable = new class implements \JsonSerializable {
            public function jsonSerialize(): array {
                return ['v' => [1, 2]];
            }
        };
        self::assertSame('v[1 2]', Ron::encode($serializable, pretty: false));
    }

    public function testEncodePreservesIntegerAndFloatText(): void {
        self::assertSame('n 9223372036854775807', Ron::encode(['n' => PHP_INT_MAX], pretty: false));
        self::assertSame('x -12.5', Ron::encode(['x' => -12.5], pretty: false));
        self::assertSame('x 0.1', Ron::encode(['x' => 0.1], pretty: false));
    }

    public function testEncodeRejectsNonFiniteFloats(): void {
        $this->expectException(RonException::class);
        Ron::encode(INF);
    }

    public function testEncodeRejectsNan(): void {
        $this->expectException(RonException::class);
        Ron::encode(NAN);
    }

    public function testEncodeRejectsResources(): void {
        $handle = fopen('php://memory', 'r');

        try {
            $this->expectException(RonException::class);
            Ron::encode($handle);
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    public function testEncodeMatchesFromJsonForJsonSafeData(): void {
        $data = ['users' => [['id' => 1, 'name' => 'Ada', 'roles' => ['admin']]], 'count' => 1];
        self::assertSame(
            Ron::canonicalRon((string) json_encode($data)),
            Ron::encode($data, pretty: false),
        );
    }

    public function testDecodeRoundTrip(): void {
        $data = ['users' => [['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Grace']], 'count' => 2];
        self::assertSameJsonValue($data, Ron::decode(Ron::encode($data)));
    }

    public function testDecodeReturnsArraysOrObjects(): void {
        self::assertSame(['a' => 1, 'b' => 2], Ron::decode('a 1 b 2'));

        $object = Ron::decode('a 1', associative: false);
        self::assertInstanceOf(\stdClass::class, $object);
        self::assertSame(1, $object->a);
    }
}
