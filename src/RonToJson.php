<?php

declare(strict_types=1);

namespace Mbolli\Ron;

use Mbolli\Ron\Value\RonObject;

/**
 * Streaming RON -> JSON converter (port of ron-go's json_direct.go + ToJSONInto).
 *
 * No intermediate value tree is built: values are written to the output buffer as
 * they are parsed. Object members are buffered and (when canonical) sorted before
 * emission, since JSON object key order is the only thing that needs look-ahead.
 */
final class RonToJson extends Scanner {
    private string $out = '';
    private bool $canonical = true;
    private string $indent = '';
    private int $maxDepth = 512;

    public function __construct(string $src) {
        $this->src = $src;
        $this->len = \strlen($src);
    }

    public function convert(bool $pretty, bool $canonical, int $maxDepth = 512): string {
        $this->canonical = $canonical;
        $this->maxDepth = $maxDepth;
        $this->indent = $pretty ? '  ' : '';
        $this->out = '';
        $this->pos = 0;

        $this->skipSpace();
        if ($this->pos < $this->len && $this->src[$this->pos] !== '{' && $this->src[$this->pos] !== '[') {
            $start = $this->pos;
            $object = new RonObject();
            while (true) {
                $this->skipSpace();
                if ($this->pos >= $this->len) {
                    $this->writeJsonObject($object, 0);

                    return $this->out;
                }
                $c = $this->src[$this->pos];
                if ($c === '{' || $c === '[') {
                    break;
                }

                try {
                    $key = $this->parseKey();
                    $value = $this->renderValueToString(1);
                } catch (RonException) {
                    break;
                }
                $object->set($key, $value);
            }

            // Elision failed: discard the partial object and parse a single root value.
            $this->out = '';
            $this->pos = $start;
        }

        $this->writeJsonValue(0);
        $this->skipSpace();
        if ($this->pos !== $this->len) {
            throw RonException::at('unexpected trailing data', $this->pos);
        }

        return $this->out;
    }

    private function renderValueToString(int $depth): string {
        // No try/finally needed: a parse error aborts the whole conversion, so the
        // saved buffer never has to be restored on the exception path.
        $saved = $this->out;
        $this->out = '';
        $this->writeJsonValue($depth);
        $captured = $this->out;
        $this->out = $saved;

        return $captured;
    }

    private function writeJsonValue(int $depth): void {
        $this->skipSpace();
        $this->writeJsonValueCurrent($depth);
    }

    private function writeJsonValueCurrent(int $depth): void {
        if ($this->pos >= $this->len) {
            throw RonException::at('expected value', $this->pos);
        }

        switch ($this->src[$this->pos]) {
            case '{':
                if ($depth >= $this->maxDepth) {
                    throw RonException::at('maximum nesting depth exceeded', $this->pos);
                }
                ++$this->pos;
                $object = new RonObject();
                while (true) {
                    $this->skipWhitespace();
                    if ($this->pos >= $this->len) {
                        throw RonException::at('expected }', $this->pos);
                    }
                    if ($this->src[$this->pos] === '}') {
                        ++$this->pos;
                        $this->writeJsonObject($object, $depth);

                        return;
                    }
                    $key = $this->parseKey();
                    $this->skipWhitespace();
                    $object->set($key, $this->renderValueToString($depth + 1));
                    $this->skipSeparators();
                }

                // unreachable
                // no break
            case '[':
                if ($depth >= $this->maxDepth) {
                    throw RonException::at('maximum nesting depth exceeded', $this->pos);
                }
                ++$this->pos;
                $this->skipWhitespace();
                if ($this->pos >= $this->len) {
                    throw RonException::at('expected ]', $this->pos);
                }
                if ($this->src[$this->pos] === ']') {
                    ++$this->pos;
                    $this->out .= '[]';

                    return;
                }
                if ($this->indent === '') {
                    $this->out .= '[';
                    $i = 0;
                    while (true) {
                        if ($i > 0) {
                            $this->out .= ',';
                        }
                        $this->writeJsonValueCurrent($depth + 1);
                        $this->skipSeparators();
                        if ($this->pos >= $this->len) {
                            throw RonException::at('expected ]', $this->pos);
                        }
                        if ($this->src[$this->pos] === ']') {
                            ++$this->pos;
                            $this->out .= ']';

                            return;
                        }
                        ++$i;
                    }
                }
                $this->out .= "[\n";
                while (true) {
                    $this->out .= str_repeat($this->indent, $depth + 1);
                    $this->writeJsonValueCurrent($depth + 1);
                    $this->skipSeparators();
                    if ($this->pos >= $this->len) {
                        throw RonException::at('expected ]', $this->pos);
                    }
                    if ($this->src[$this->pos] === ']') {
                        ++$this->pos;
                        $this->out .= "\n" . str_repeat($this->indent, $depth) . ']';

                        return;
                    }
                    $this->out .= ",\n";
                }

                // unreachable
                // no break
            case ',':
                $this->out .= $this->jsonQuote($this->parseCommaPrefixedToken());

                return;

            case "'":
                $this->out .= $this->jsonQuote($this->parseApostropheValue());

                return;

            case '"':
                $this->out .= $this->jsonQuote($this->parseQuotedString());

                return;
        }

        $token = $this->tokenSpan();
        if ($token === 'true' || $token === 'false' || $token === 'null') {
            $this->out .= $token;
        } elseif (self::looksLikeNumber($token)) {
            $this->out .= $token;
        } else {
            $this->out .= $this->jsonQuote($token);
        }
    }

    private function writeJsonObject(RonObject $object, int $depth): void {
        if ($object->count() === 0) {
            $this->out .= '{}';

            return;
        }
        $keys = $object->keys;
        $values = $object->values; // pre-rendered JSON value text
        if ($this->canonical) {
            Canonical::sortKeyedValues($keys, $values);
        }
        $count = \count($keys);

        if ($this->indent === '') {
            $this->out .= '{';
            for ($i = 0; $i < $count; ++$i) {
                if ($i > 0) {
                    $this->out .= ',';
                }
                $value = $values[$i];
                \assert(\is_string($value));
                $this->out .= $this->jsonQuote($keys[$i]) . ':' . $value;
            }
            $this->out .= '}';

            return;
        }

        $this->out .= "{\n";
        $inner = str_repeat($this->indent, $depth + 1);
        for ($i = 0; $i < $count; ++$i) {
            $value = $values[$i];
            \assert(\is_string($value));
            $this->out .= $inner . $this->jsonQuote($keys[$i]) . ': ' . $value;
            if ($i + 1 < $count) {
                $this->out .= ',';
            }
            $this->out .= "\n";
        }
        $this->out .= str_repeat($this->indent, $depth) . '}';
    }

    private function jsonQuote(string $s): string {
        return JsonString::quote($s);
    }
}
