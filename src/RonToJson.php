<?php

declare(strict_types=1);

namespace Mbolli\Ron;

use Mbolli\Ron\Value\RonObject;

/**
 * Streaming RON -> JSON converter (port of ron-go's json_direct.go + ToJSONInto).
 *
 * No intermediate value tree is built: values are written to the output buffer as
 * they are parsed. Object members are buffered so a whole object is available before
 * emission, which is what canonical key sorting needs.
 *
 * Pretty and compact preserve source member order. Canonical is not "compact plus a
 * sort": it emits compact JSON with duplicate member names left intact and then hands
 * the bytes to {@see Rfc8785}, which applies the full RFC 8785 / I-JSON contract.
 */
final class RonToJson extends Scanner {
    private string $out = '';
    private bool $rejectDuplicateKeys = false;
    private string $indent = '';
    private int $maxDepth = 512;

    public function __construct(string $src) {
        $this->setSource($src);
    }

    public function convert(RonMode $mode = RonMode::Pretty, int $maxDepth = 512): string {
        if ($mode === RonMode::Canonical) {
            // Duplicate names must survive the RON pass so Rfc8785 can reject them;
            // the RON-level check catches names that only collide once decoded
            // (`{a 1 \u0061 2}`), which a deduplicating object would have collapsed.
            $this->rejectDuplicateKeys = true;

            return Rfc8785::canonicalize($this->convertText('', $maxDepth), $maxDepth);
        }

        return $this->convertText($mode === RonMode::Pretty ? '  ' : '', $maxDepth);
    }

    private function convertText(string $indent, int $maxDepth): string {
        $this->maxDepth = $maxDepth;
        $this->indent = $indent;
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
                    // skipWhitespace, not skipSpace: a comma here starts the value token
                    // (`,foo` is the string ",foo"), it is not a member separator.
                    $this->skipWhitespace();
                    $value = $this->renderValueToString(1);
                } catch (RonException $e) {
                    // A failed parse here just means the input is not a brace-elided
                    // root object, so fall back. A canonical violation is a real error
                    // about the document and must not be masked by that fallback.
                    if ($e->isCanonicalViolation()) {
                        throw $e;
                    }

                    break;
                }
                $this->setMember($object, $key, $value);
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
        $this->writeJsonValueCurrent($depth);
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
                    $this->setMember($object, $key, $this->renderValueToString($depth + 1));
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

        // Classification looks at the raw source spelling, before escape decoding:
        // `tr\u0075e` is the string "true", not the boolean.
        [$start, $end] = $this->tokenSpanBounds();
        $token = substr($this->src, $start, $end - $start);
        if ($token === 'true' || $token === 'false' || $token === 'null') {
            $this->out .= $token;
        } elseif (self::looksLikeNumber($token)) {
            $this->out .= $token;
        } else {
            $this->out .= $this->jsonQuote($this->decodeStringSpan($start, $end));
        }
    }

    /**
     * Add a member, honouring the canonical-mode duplicate-name rejection. Outside
     * canonical mode RonObject's last-wins-at-last-position dedup applies, matching
     * how the rest of the library treats repeated names.
     */
    private function setMember(RonObject $object, string $key, string $value): void {
        if ($this->rejectDuplicateKeys && $object->has($key)) {
            throw RonException::canonical('duplicate object name in canonical RON');
        }
        $object->set($key, $value);
    }

    private function writeJsonObject(RonObject $object, int $depth): void {
        if ($object->count() === 0) {
            $this->out .= '{}';

            return;
        }
        // Member order is always source order here: canonical output is produced by
        // handing these bytes to Rfc8785, which does the sorting.
        $keys = $object->keys;
        $values = $object->values; // pre-rendered JSON value text
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
