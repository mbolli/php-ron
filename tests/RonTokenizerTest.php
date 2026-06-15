<?php

declare(strict_types=1);

namespace Mbolli\Ron\Tests;

use Mbolli\Ron\Ron;
use Mbolli\Ron\Value\RonToken;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the role-aware, lenient RON tokenizer (Ron::tokenize).
 */
final class RonTokenizerTest extends TestCase {
    private const string CONFORMANCE_DIR = __DIR__ . '/corpus/ron/testdata/conformance';

    public function testRootElisionDistinguishesKeysFromValues(): void {
        self::assertSame(
            [[0, 6, 'key'], [7, 4, 'bool']],
            self::simplify('active true'),
        );
    }

    public function testCompactRecordRolesAndArrayElements(): void {
        // Array elements (admin, writer) are values, not keys; keys are only the
        // first token of each object pair (id, name, roles, active).
        self::assertSame(
            [
                [0, 1, 'punctuation'],
                [1, 2, 'key'],
                [4, 3, 'number'],
                [8, 4, 'key'],
                [13, 3, 'string'],
                [17, 5, 'key'],
                [23, 1, 'punctuation'],
                [24, 5, 'string'],
                [30, 6, 'string'],
                [36, 1, 'punctuation'],
                [38, 6, 'key'],
                [45, 4, 'bool'],
                [49, 1, 'punctuation'],
            ],
            self::simplify('{id 100 name Ada roles [admin writer] active true}'),
        );
    }

    public function testRepeatedQuoteStringsSpanVerbatim(): void {
        // Five apostrophes are one string token (decodes to a single apostrophe);
        // the span covers all five source bytes.
        self::assertSame(
            [[0, 1, 'punctuation'], [2, 5, 'string'], [8, 1, 'punctuation']],
            self::simplify("[ ''''' ]"),
        );

        // Doubled-quote run: the inner quotes are content, the whole run is one token.
        self::assertSame(
            [[0, 1, 'punctuation'], [2, 21, 'string'], [24, 1, 'punctuation']],
            self::simplify('[ ""a "quoted" phrase"" ]'),
        );
    }

    public function testCommaPrefixedAndApostropheTokensAreStrings(): void {
        self::assertSame(
            [[0, 1, 'punctuation'], [1, 4, 'string'], [5, 1, 'punctuation']],
            self::simplify('[,foo]'),
        );
        self::assertSame(
            [[0, 1, 'punctuation'], [2, 1, 'string'], [4, 1, 'punctuation']],
            self::simplify("[ ' ]"),
        );
    }

    public function testNumbersBooleansAndNull(): void {
        self::assertSame(
            [
                [0, 1, 'punctuation'],
                [2, 8, 'number'],
                [11, 1, 'number'],
                [13, 2, 'number'],
                [16, 4, 'bool'],
                [21, 5, 'bool'],
                [27, 4, 'null'],
                [32, 1, 'punctuation'],
            ],
            self::simplify('[ -12.5e+2 0 -0 true false null ]'),
        );
    }

    public function testNumericLikeKeyStaysKey(): void {
        // Keys never coerce: 123 in key position is a key string, not a number.
        self::assertSame(
            [[0, 1, 'punctuation'], [1, 3, 'key'], [5, 3, 'string'], [8, 1, 'punctuation']],
            self::simplify('{123 foo}'),
        );
    }

    public function testQuotedKeySpanIncludesQuotes(): void {
        self::assertSame(
            [[0, 1, 'punctuation'], [1, 3, 'key'], [5, 1, 'string'], [6, 1, 'punctuation']],
            self::simplify('{"k" v}'),
        );
    }

    public function testSingleRootScalarsReparseAfterElisionFails(): void {
        self::assertSame([[0, 2, 'number']], self::simplify('42'));
        self::assertSame([[0, 5, 'string']], self::simplify('hello'));
    }

    public function testMalformedInputIsLenient(): void {
        // Unterminated object: returns the tokens scanned so far, never throws.
        self::assertSame(
            [[0, 1, 'punctuation'], [1, 1, 'key'], [3, 1, 'number']],
            self::simplify('{a 1 '),
        );
        // Input that cannot be classified at all yields no tokens (still no throw).
        self::assertSame([], self::simplify('}'));
        self::assertSame([], self::simplify(''));
    }

    public function testStringsCorpusAreAllStringValues(): void {
        $file = self::CONFORMANCE_DIR . '/valid/basic/strings/input.ron';
        if (!is_file($file)) {
            self::markTestSkipped('RON corpus submodule missing; run: composer install');
        }
        $src = (string) file_get_contents($file);
        $tokens = Ron::tokenize($src);

        self::assertNotEmpty($tokens);
        self::assertSame('punctuation', $tokens[0]->kind->value, 'opens with [');
        self::assertSame('punctuation', $tokens[\count($tokens) - 1]->kind->value, 'closes with ]');
        foreach (\array_slice($tokens, 1, -1) as $token) {
            self::assertSame('string', $token->kind->value);
        }
    }

    /**
     * Every accepted conformance document must tokenize without throwing, and the
     * spans must be in order, non-overlapping, in bounds, and non-empty.
     */
    public function testAllValidCorpusInputsProduceWellFormedSpans(): void {
        $dir = self::CONFORMANCE_DIR . '/valid';
        if (!is_dir($dir)) {
            self::markTestSkipped('RON corpus submodule missing; run: composer install');
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getFilename() !== 'input.ron') {
                continue;
            }
            ++$count;
            $src = (string) file_get_contents($file->getPathname());
            $len = \strlen($src);
            $prevEnd = 0;
            foreach (Ron::tokenize($src) as $token) {
                self::assertGreaterThan(0, $token->length, $file->getPathname());
                self::assertGreaterThanOrEqual($prevEnd, $token->offset, $file->getPathname());
                self::assertLessThanOrEqual($len, $token->offset + $token->length, $file->getPathname());
                $prevEnd = $token->offset + $token->length;
            }
        }

        self::assertGreaterThan(0, $count, 'expected at least one corpus input');
    }

    /**
     * @return list<array{int, int, string}>
     */
    private static function simplify(string $ron): array {
        return array_map(
            static fn (RonToken $t): array => [$t->offset, $t->length, $t->kind->value],
            Ron::tokenize($ron),
        );
    }
}
