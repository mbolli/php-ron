<?php

declare(strict_types=1);

namespace Mbolli\Ron;

use Mbolli\Ron\Value\RonNumber;
use Mbolli\Ron\Value\RonObject;

/**
 * Value-model -> RON renderer (port of ron-go's render.go).
 *
 * Handles both pretty and compact output, canonical key ordering, root-object
 * elision, and the inline-when-small heuristic (<= 80 bytes). Methods return
 * strings rather than writing to a shared buffer so size estimation (renderScalar)
 * can reuse the same code paths, mirroring the Go reference.
 */
final class RonRenderer {
    /** ASCII structural delimiters: { } [ ] " ' , space tab LF CR. */
    private const string STRUCTURAL = "{}[]\"',\x20\x09\x0A\x0D";

    private const int INLINE_LIMIT = 80;

    public function __construct(
        private readonly bool $pretty = true,
        private readonly bool $canonical = true,
    ) {}

    public function render(mixed $value): string {
        if (!$this->pretty) {
            return $this->writeCompactValue($value, true);
        }

        if ($value instanceof RonObject && $value->count() > 0) {
            // Root object members render at depth 0 without outer braces.
            return $this->writeObjectMembers($this->members($value), '  ', -1) . "\n";
        }

        return $this->writeValue($value, '  ', 0) . "\n";
    }

    public static function renderString(string $value, bool $isKey): string {
        if (!self::isStructural($value)) {
            if ($isKey) {
                return $value;
            }
            if (
                $value !== 'true' && $value !== 'false' && $value !== 'null'
                && !Scanner::looksLikeNumber($value)
            ) {
                return $value;
            }
        }

        $longest = self::longestApostropheRun($value);
        $delimiter = str_repeat("'", $longest + 1);

        return $delimiter . $value . $delimiter;
    }

    private function writeValue(mixed $value, string $indent, int $depth): string {
        if ($value === null) {
            return 'null';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (\is_string($value)) {
            return self::renderString($value, false);
        }
        if ($value instanceof RonNumber) {
            return $value->text;
        }
        if ($value instanceof RonObject) {
            return $this->writeObject($value, $indent, $depth);
        }
        if (\is_array($value)) {
            return $this->writeArray($value, $indent, $depth);
        }

        throw new RonException('ron: unsupported value type');
    }

    private function writeObject(RonObject $object, string $indent, int $depth): string {
        $members = $this->members($object);
        if ($members === []) {
            return '{}';
        }
        if ($this->shouldInlineObject($members)) {
            $out = '{';
            foreach ($members as [$key, $value]) {
                $out .= self::renderString($key, true) . ' ' . $this->writeValue($value, $indent, $depth);
            }

            return $out . '}';
        }

        return "{\n" . $this->writeObjectMembers($members, $indent, $depth)
            . "\n" . str_repeat($indent, $depth) . '}';
    }

    /** @param list<array{0: string, 1: mixed}> $members */
    private function writeObjectMembers(array $members, string $indent, int $depth): string {
        $out = '';
        $count = \count($members);
        $inner = str_repeat($indent, $depth + 1);
        foreach ($members as $i => [$key, $value]) {
            $out .= $inner . self::renderString($key, true) . ' '
                . $this->writeValue($value, $indent, $depth + 1);
            if ($i + 1 < $count) {
                $out .= "\n";
            }
        }

        return $out;
    }

    /** @param array<array-key, mixed> $array */
    private function writeArray(array $array, string $indent, int $depth): string {
        if ($array === []) {
            return '[]';
        }
        if ($this->shouldInlineArray($array)) {
            $out = '[';
            $first = true;
            foreach ($array as $value) {
                if (!$first) {
                    $out .= ' ';
                }
                $first = false;
                $out .= $this->writeValue($value, $indent, $depth);
            }

            return $out . ']';
        }

        $out = "[\n";
        $count = \count($array);
        $inner = str_repeat($indent, $depth + 1);
        $i = 0;
        foreach ($array as $value) {
            $out .= $inner . $this->writeValue($value, $indent, $depth + 1);
            if (++$i < $count) {
                $out .= "\n";
            }
        }

        return $out . "\n" . str_repeat($indent, $depth) . ']';
    }

    private function writeCompactValue(mixed $value, bool $top): string {
        if ($value === null) {
            return 'null';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (\is_string($value)) {
            return self::renderString($value, false);
        }
        if ($value instanceof RonNumber) {
            return $value->text;
        }
        if (\is_array($value)) {
            $out = '[';
            $first = true;
            foreach ($value as $child) {
                if (!$first) {
                    $out .= ' ';
                }
                $first = false;
                $out .= $this->writeCompactValue($child, false);
            }

            return $out . ']';
        }
        if (!$value instanceof RonObject) {
            throw new RonException('ron: unsupported value type');
        }

        if ($value->count() === 0) {
            return '{}';
        }
        $keys = $value->keys;
        $values = $value->values;
        if ($this->canonical) {
            Canonical::sortKeyedValues($keys, $values);
        }
        $count = \count($keys);
        $out = $top ? '' : '{';
        for ($i = 0; $i < $count; ++$i) {
            if ($i > 0) {
                $out .= ' ';
            }
            $out .= self::renderString($keys[$i], true);

            $child = $values[$i];
            $needsSpace = true;
            if ($child !== null) {
                if (\is_string($child)) {
                    $rendered = self::renderString($child, false);
                    $needsSpace = $rendered === '' || ($rendered[0] !== "'" && $rendered[0] !== '"');
                } elseif (\is_array($child) || $child instanceof RonObject) {
                    $needsSpace = false;
                }
            }
            if ($needsSpace) {
                $out .= ' ';
            }
            $out .= $this->writeCompactValue($child, false);
        }

        return $top ? $out : $out . '}';
    }

    /** @param list<array{0: string, 1: mixed}> $members */
    private function shouldInlineObject(array $members): bool {
        if (\count($members) !== 1) {
            return false;
        }
        $size = 2;
        foreach ($members as [$key, $value]) {
            if (!$this->canInline($value)) {
                return false;
            }
            $size += \strlen(self::renderString($key, true)) + 1 + \strlen($this->renderScalar($value));
        }

        return $size <= self::INLINE_LIMIT;
    }

    /** @param array<array-key, mixed> $array */
    private function shouldInlineArray(array $array): bool {
        $size = 2;
        $first = true;
        foreach ($array as $value) {
            if (!$this->canInline($value)) {
                return false;
            }
            if (!$first) {
                ++$size;
            }
            $first = false;
            $size += \strlen($this->renderScalar($value));
        }

        return $size <= self::INLINE_LIMIT;
    }

    private function canInline(mixed $value): bool {
        if ($value instanceof RonObject) {
            return $this->shouldInlineObject($this->members($value));
        }
        if (\is_array($value)) {
            return $this->shouldInlineArray($value);
        }

        return true; // null, bool, string, RonNumber
    }

    /** Inline rendering of a value, used only for size estimation. */
    private function renderScalar(mixed $value): string {
        return $this->writeValue($value, '', 0);
    }

    /**
     * @return list<array{0: string, 1: mixed}>
     */
    private function members(RonObject $object): array {
        $members = $object->members();
        if ($this->canonical) {
            $members = Canonical::sortMembers($members);
        }

        return $members;
    }

    private static function isStructural(string $value): bool {
        if ($value === '') {
            return true;
        }
        $len = \strlen($value);
        // Small (11-byte) mask: cheap to build, stops at any structural ASCII byte.
        if (strcspn($value, self::STRUCTURAL) !== $len) {
            return true;
        }
        // No structural ASCII byte. Unicode whitespace only exists if there are high
        // bytes, so a pure-ASCII string is bare-eligible.
        if (mb_check_encoding($value, 'ASCII')) {
            return false;
        }
        $i = 0;
        while ($i < $len) {
            $b = \ord($value[$i]);
            if ($b < 0x80) {
                ++$i;

                continue;
            }
            [$rune, $size] = Utf8::decodeRune($value, $i, $len);
            if (Utf8::isSpaceAbove($rune)) {
                return true;
            }
            $i += $size;
        }

        return false;
    }

    private static function longestApostropheRun(string $value): int {
        $longest = 0;
        $run = 0;
        $len = \strlen($value);
        for ($i = 0; $i < $len; ++$i) {
            if ($value[$i] === "'") {
                ++$run;
                if ($run > $longest) {
                    $longest = $run;
                }
            } else {
                $run = 0;
            }
        }

        return $longest;
    }
}
