<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Property-timezone presentation helpers. Storage and bucketing are UTC;
 * local calendar days are converted to UTC ranges, so DST days have 23 or 25
 * hours (spec §11).
 */
final class LocalTime
{
    /**
     * UTC [start, end) range covering a local calendar date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function dayRange(string $localDate, string $timezone): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $localDate.' 00:00:00', $timezone)->startOfDay();
        $end = $start->addDay()->startOfDay();

        return [$start->utc(), $end->utc()];
    }

    /**
     * Unambiguous local display including the zone abbreviation and offset.
     */
    public static function display(?DateTimeInterface $value, string $timezone, bool $withSeconds = true): string
    {
        if ($value === null) {
            return '—';
        }

        $local = CarbonImmutable::instance($value)->setTimezone($timezone);

        return $local->format($withSeconds ? 'Y-m-d H:i:s T (P)' : 'Y-m-d H:i T (P)');
    }

    public static function displayShort(?DateTimeInterface $value, string $timezone): string
    {
        if ($value === null) {
            return '—';
        }

        return CarbonImmutable::instance($value)->setTimezone($timezone)->format('M j, g:i:s A T');
    }

    public static function age(?DateTimeInterface $value, ?CarbonImmutable $now = null): string
    {
        if ($value === null) {
            return 'never';
        }

        $now ??= CarbonImmutable::now();

        return CarbonImmutable::instance($value)->diffForHumans($now, ['parts' => 2, 'short' => true, 'syntax' => CarbonImmutable::DIFF_RELATIVE_TO_NOW]);
    }
}
