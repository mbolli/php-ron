<?php

declare(strict_types=1);

namespace Mbolli\Ron\Vocabulary;

/**
 * Time typed vocabulary: UTC instants and day-time durations.
 *
 * Rules mirror ron-go's vocabulary_time.go: `#utc` is an RFC 3339 UTC instant
 * ending in `Z` with a valid calendar date and no leap second; `#dur` is a
 * restricted ISO 8601 day-time duration (no years, months, or weeks).
 */
final class TimeVocabulary {
    public const string URI = 'https://ron.dev/vocab/time/v1';

    private const int MAX_FRACTION_DIGITS = 9;

    /** @return array<string, \Closure(mixed, VocabularyValidator): mixed> */
    public static function validators(): array {
        return [
            '#utc' => static fn (mixed $p, VocabularyValidator $v): mixed => self::utc($p),
            '#dur' => static fn (mixed $p, VocabularyValidator $v): mixed => self::dur($p),
        ];
    }

    private static function utc(mixed $payload): string {
        if (!\is_string($payload)
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d{1,9})?Z$/', $payload, $m) !== 1
        ) {
            Payload::reject('#utc');
        }
        $month = (int) $m[2];
        $day = (int) $m[3];
        $year = (int) $m[1];
        $hour = (int) $m[4];
        $minute = (int) $m[5];
        $second = (int) $m[6];
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            Payload::reject('#utc'); // invalid date, time component, or leap second
        }

        return $payload;
    }

    private static function dur(mixed $payload): string {
        if (!\is_string($payload) || !self::isDayTimeDuration($payload)) {
            Payload::reject('#dur');
        }

        return $payload;
    }

    private static function isDayTimeDuration(string $value): bool {
        $len = \strlen($value);
        if ($len === 0) {
            return false;
        }
        $pos = 0;
        if ($value[0] === '-') {
            $pos = 1;
            if ($pos === $len) {
                return false;
            }
        }
        if ($len - $pos < 2 || $value[$pos] !== 'P') {
            return false;
        }
        ++$pos;
        $sawComponent = false;

        // Optional leading day component: digits followed by 'D'.
        if ($pos < $len && ctype_digit($value[$pos])) {
            $start = $pos;
            while ($pos < $len && ctype_digit($value[$pos])) {
                ++$pos;
            }
            if ($pos < $len && $value[$pos] === 'D') {
                $sawComponent = true;
                ++$pos;
            } else {
                $pos = $start; // not days; must be a time component after 'T'
            }
        }
        if ($pos === $len) {
            return $sawComponent;
        }
        if ($value[$pos] !== 'T') {
            return false;
        }
        ++$pos;
        if ($pos === $len) {
            return false;
        }

        $seenHour = false;
        $seenMinute = false;
        $seenSecond = false;
        while ($pos < $len) {
            $start = $pos;
            while ($pos < $len && ctype_digit($value[$pos])) {
                ++$pos;
            }
            if ($start === $pos) {
                return false;
            }
            $hasFraction = false;
            if ($pos < $len && $value[$pos] === '.') {
                ++$pos;
                $fractionStart = $pos;
                while ($pos < $len && ctype_digit($value[$pos])) {
                    ++$pos;
                }
                if ($fractionStart === $pos || $pos - $fractionStart > self::MAX_FRACTION_DIGITS) {
                    return false;
                }
                $hasFraction = true;
            }
            if ($pos === $len) {
                return false;
            }
            $unit = $value[$pos];
            ++$pos;
            if ($unit === 'H') {
                if ($hasFraction || $seenHour || $seenMinute || $seenSecond) {
                    return false;
                }
                $seenHour = true;
            } elseif ($unit === 'M') {
                if ($hasFraction || $seenMinute || $seenSecond) {
                    return false;
                }
                $seenMinute = true;
            } elseif ($unit === 'S') {
                if ($seenSecond) {
                    return false;
                }
                $seenSecond = true;
            } else {
                return false;
            }
            $sawComponent = true;
        }

        return $sawComponent;
    }
}
