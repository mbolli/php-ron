<?php

declare(strict_types=1);

namespace Mbolli\Ron;

use Mbolli\Ron\Value\RonToken;
use Mbolli\Ron\Value\RonTokenKind;

/**
 * Role-aware, lenient lexer over RON source (for tooling such as syntax highlighters).
 *
 * Mirrors the parse structure of {@see RonToJson} (same dispatch, same root-object
 * elision) but emits {@see RonToken} source spans instead of writing JSON, and never
 * sorts or quotes. The existing {@see Scanner} primitives are reused unchanged: each
 * token span is captured by reading {@see Scanner::$pos} immediately before and after
 * the relevant primitive call.
 *
 * Unlike the strict converters, tokenizing is best-effort: a {@see RonException} from
 * malformed input or the depth guard simply stops scanning and returns the tokens
 * collected so far, so a highlighter is never aborted by invalid input.
 */
final class RonTokenizer extends Scanner {
    private int $maxDepth = 512;

    /** @var list<RonToken> */
    private array $tokens = [];

    public function __construct(string $src) {
        $this->src = $src;
        $this->len = \strlen($src);
    }

    /**
     * @return list<RonToken>
     */
    public function tokenize(int $maxDepth = 512): array {
        $this->maxDepth = $maxDepth;
        $this->pos = 0;
        $this->tokens = [];

        try {
            $this->skipSpace();
            if ($this->pos < $this->len && $this->src[$this->pos] !== '{' && $this->src[$this->pos] !== '[') {
                $start = $this->pos;
                while (true) {
                    $this->skipSpace();
                    if ($this->pos >= $this->len) {
                        // The whole input parsed cleanly as a brace-elided root object.
                        return $this->tokens;
                    }
                    $c = $this->src[$this->pos];
                    if ($c === '{' || $c === '[') {
                        break;
                    }

                    try {
                        $this->scanKey();
                        $this->scanValue(1);
                    } catch (RonException) {
                        break;
                    }
                }

                // Elision failed (a break above): discard partial tokens and re-scan a single root value.
                $this->pos = $start;
                $this->tokens = [];
            }

            $this->scanValue(0);
        } catch (RonException) {
            // Lenient: return whatever was classified before the error.
        }

        return $this->tokens;
    }

    /** Skip leading top-level space, then scan one value. Mirrors RonToJson::writeJsonValue. */
    private function scanValue(int $depth): void {
        $this->skipSpace();
        $this->scanValueCurrent($depth);
    }

    /** Scan one value at the current position. Mirrors RonToJson::writeJsonValueCurrent. */
    private function scanValueCurrent(int $depth): void {
        if ($this->pos >= $this->len) {
            throw RonException::at('expected value', $this->pos);
        }

        switch ($this->src[$this->pos]) {
            case '{':
                if ($depth >= $this->maxDepth) {
                    throw RonException::at('maximum nesting depth exceeded', $this->pos);
                }
                $this->punctuation();
                while (true) {
                    $this->skipWhitespace();
                    if ($this->pos >= $this->len) {
                        throw RonException::at('expected }', $this->pos);
                    }
                    if ($this->src[$this->pos] === '}') {
                        $this->punctuation();

                        return;
                    }
                    $this->scanKey();
                    $this->scanValue($depth + 1);
                    $this->skipSeparators();
                }

                // no break
            case '[':
                if ($depth >= $this->maxDepth) {
                    throw RonException::at('maximum nesting depth exceeded', $this->pos);
                }
                $this->punctuation();
                $this->skipWhitespace();
                if ($this->pos >= $this->len) {
                    throw RonException::at('expected ]', $this->pos);
                }
                if ($this->src[$this->pos] === ']') {
                    $this->punctuation();

                    return;
                }
                while (true) {
                    $this->scanValueCurrent($depth + 1);
                    $this->skipSeparators();
                    if ($this->pos >= $this->len) {
                        throw RonException::at('expected ]', $this->pos);
                    }
                    if ($this->src[$this->pos] === ']') {
                        $this->punctuation();

                        return;
                    }
                }

                // no break
            case ',':
                $start = $this->pos;
                $this->parseCommaPrefixedToken();
                $this->emit($start, RonTokenKind::String);

                return;

            case "'":
                $start = $this->pos;
                $this->parseApostropheValue();
                $this->emit($start, RonTokenKind::String);

                return;

            case '"':
                $start = $this->pos;
                $this->parseQuotedString();
                $this->emit($start, RonTokenKind::String);

                return;
        }

        $start = $this->pos;
        $token = $this->tokenSpan();
        if ($token === 'true' || $token === 'false') {
            $this->emit($start, RonTokenKind::Bool);
        } elseif ($token === 'null') {
            $this->emit($start, RonTokenKind::Null);
        } elseif (self::looksLikeNumber($token)) {
            $this->emit($start, RonTokenKind::Number);
        } else {
            $this->emit($start, RonTokenKind::String);
        }
    }

    /** Scan one object key. Reuses Scanner::parseKey (incl. its delimiter rejection). */
    private function scanKey(): void {
        $start = $this->pos;
        $this->parseKey();
        $this->emit($start, RonTokenKind::Key);
    }

    /** Emit a length-1 punctuation token for the current byte and advance past it. */
    private function punctuation(): void {
        $this->tokens[] = new RonToken($this->pos, 1, RonTokenKind::Punctuation);
        ++$this->pos;
    }

    /** Emit a token spanning [$start, current pos). */
    private function emit(int $start, RonTokenKind $kind): void {
        $this->tokens[] = new RonToken($start, $this->pos - $start, $kind);
    }
}
