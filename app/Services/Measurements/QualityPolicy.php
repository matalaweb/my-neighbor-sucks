<?php

namespace App\Services\Measurements;

use App\Enums\Metric;
use App\Enums\QualityFlag;

/**
 * Versioned server quality policy deciding which reported values contribute
 * to aggregates (spec §4, §11). Raw values and flags are always preserved;
 * this only governs official summaries. Documented in
 * docs/architecture/0003-aggregation-and-quality-policy.md.
 *
 * qp-2026-10-v1:
 *  - microphone_disconnected, audio_dropout, incomplete_interval,
 *    processing_error, clipping: exclude every metric for the interval
 *    (values are missing, partial, or lower bounds)
 *  - unsynchronized_clock: exclude every metric (timing is untrusted, so the
 *    value cannot be placed in a trusted time bucket)
 *  - invalid_calibration: exclude absolute SPL metrics; keep dBFS
 *  - below_noise_floor: exclude every metric (value is an upper bound)
 *  - ambiguous overlap (two boots/streams reporting the same interval for
 *    one channel): excluded from official summaries
 */
final class QualityPolicy
{
    public const VERSION = 'qp-2026-10-v1';

    private const EXCLUDE_ALL = [
        QualityFlag::MicrophoneDisconnected,
        QualityFlag::AudioDropout,
        QualityFlag::IncompleteInterval,
        QualityFlag::ProcessingError,
        QualityFlag::Clipping,
        QualityFlag::UnsynchronizedClock,
        QualityFlag::BelowNoiseFloor,
    ];

    private const EXCLUDE_ABSOLUTE = [
        QualityFlag::InvalidCalibration,
    ];

    public static function excludeAllMask(): int
    {
        return QualityFlag::toMask(self::EXCLUDE_ALL);
    }

    public static function excludeAbsoluteMask(): int
    {
        return QualityFlag::toMask(self::EXCLUDE_ABSOLUTE);
    }

    public static function excludes(Metric $metric, int $qualityMask): bool
    {
        if (($qualityMask & self::excludeAllMask()) !== 0) {
            return true;
        }

        return $metric->isAbsolute() && ($qualityMask & self::excludeAbsoluteMask()) !== 0;
    }

    /** Whole-interval exclusion (used for coverage accounting). */
    public static function excludesInterval(int $qualityMask): bool
    {
        return ($qualityMask & self::excludeAllMask()) !== 0;
    }

    /**
     * SQL predicate (for raw-second chart queries) that is true when a metric
     * value is usable under this policy.
     */
    public static function sqlUsable(Metric $metric, string $column = 'quality_flags'): string
    {
        $mask = self::excludeAllMask() | ($metric->isAbsolute() ? self::excludeAbsoluteMask() : 0);

        return "({$column} & {$mask}) = 0";
    }

    /**
     * @return array<string, string>
     */
    public static function describe(): array
    {
        return [
            'version' => self::VERSION,
            'excluded_for_all_metrics' => implode(', ', array_map(fn (QualityFlag $flag): string => $flag->value, self::EXCLUDE_ALL)),
            'excluded_for_absolute_metrics' => implode(', ', array_map(fn (QualityFlag $flag): string => $flag->value, self::EXCLUDE_ABSOLUTE)),
            'ambiguous_overlaps' => 'excluded',
        ];
    }
}
