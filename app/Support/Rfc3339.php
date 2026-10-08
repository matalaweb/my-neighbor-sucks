<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Strict RFC 3339 parsing/formatting. Stored and exposed timestamps are UTC.
 */
final class Rfc3339
{
    private const PATTERN = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value, $matches) !== 1) {
            return null;
        }

        $fraction = isset($matches[7]) && $matches[7] !== '' ? str_pad(substr($matches[7], 1), 6, '0') : '000000';
        $offset = $matches[8] === 'Z' ? '+00:00' : $matches[8];

        $parsed = CarbonImmutable::createFromFormat(
            'Y-m-d\TH:i:s.uP',
            sprintf('%s-%s-%sT%s:%s:%s.%s%s', $matches[1], $matches[2], $matches[3], $matches[4], $matches[5], $matches[6], $fraction, $offset),
        );

        if ($parsed === null || $parsed === false) {
            return null;
        }

        // Reject calendar/clock overflow such as 2026-02-30 or 24:61:00.
        $expected = sprintf('%s-%s-%s %s:%s:%s', $matches[1], $matches[2], $matches[3], $matches[4], $matches[5], $matches[6]);

        if ($parsed->format('Y-m-d H:i:s') !== $expected) {
            return null;
        }

        return $parsed->setTimezone('UTC');
    }

    public static function format(?DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::instance($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Microsecond-precision form used in canonical hashes. */
    public static function formatMicro(?DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::instance($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /** MySQL DATETIME(6) literal in UTC. */
    public static function toDatabase(DateTimeInterface $value): string
    {
        return CarbonImmutable::instance($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
