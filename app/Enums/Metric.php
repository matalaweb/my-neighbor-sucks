<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Explicitly named acoustic metrics (spec §4). Never display an unlabeled
 * "peak dB": every value carries its metric name and unit.
 */
enum Metric: string implements HasLabel
{
    case LAeq = 'laeq_db';
    case LAFmax = 'lafmax_db';
    case LCeq = 'lceq_db';
    case LCpeak = 'lcpeak_db';
    case LowFrequencyLeq = 'low_frequency_leq_db';
    case RmsDbfs = 'rms_dbfs';

    public function getLabel(): string
    {
        return match ($this) {
            self::LAeq => 'LAeq',
            self::LAFmax => 'LAFmax',
            self::LCeq => 'LCeq',
            self::LCpeak => 'LCpeak',
            self::LowFrequencyLeq => 'Low-frequency Leq',
            self::RmsDbfs => 'RMS level',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::LAeq, self::LAFmax => 'dBA',
            self::LCeq, self::LCpeak => 'dBC',
            self::LowFrequencyLeq => 'dB re 20 µPa',
            self::RmsDbfs => 'dBFS',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LAeq => 'Equivalent A-weighted level over the interval',
            self::LAFmax => 'Highest fast-time-weighted A-level in the interval',
            self::LCeq => 'Equivalent C-weighted level over the interval',
            self::LCpeak => 'Maximum C-weighted instantaneous peak',
            self::LowFrequencyLeq => 'Unweighted band-limited equivalent level',
            self::RmsDbfs => 'Digital RMS level relative to full scale (not a sound pressure level)',
        };
    }

    /** Equivalent-level metrics aggregate by energy; maxima aggregate with max(). */
    public function isEnergy(): bool
    {
        return match ($this) {
            self::LAeq, self::LCeq, self::LowFrequencyLeq, self::RmsDbfs => true,
            self::LAFmax, self::LCpeak => false,
        };
    }

    /** Absolute sound-pressure metrics must be null for uncalibrated streams. */
    public function isAbsolute(): bool
    {
        return $this !== self::RmsDbfs;
    }

    /** Column prefix used in measurement_rollups. */
    public function rollupPrefix(): string
    {
        return match ($this) {
            self::LAeq => 'laeq',
            self::LAFmax => 'lafmax',
            self::LCeq => 'lceq',
            self::LCpeak => 'lcpeak',
            self::LowFrequencyLeq => 'lf',
            self::RmsDbfs => 'dbfs',
        };
    }

    public function labelWithUnit(): string
    {
        return $this->getLabel().' ('.$this->unit().')';
    }

    /** @return list<self> */
    public static function energyMetrics(): array
    {
        return array_values(array_filter(self::cases(), fn (self $metric): bool => $metric->isEnergy()));
    }

    /** @return list<self> */
    public static function maxMetrics(): array
    {
        return array_values(array_filter(self::cases(), fn (self $metric): bool => ! $metric->isEnergy()));
    }
}
