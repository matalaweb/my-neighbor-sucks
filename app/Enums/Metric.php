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

    /** Everyday name for people who don't work in acoustics; getLabel() stays the standard term. */
    public function plainName(): string
    {
        return match ($this) {
            self::LAeq => 'Average loudness',
            self::LAFmax => 'Loudest moment',
            self::LCeq => 'Average loudness incl. bass',
            self::LCpeak => 'Sharpest bang',
            self::LowFrequencyLeq => 'Low rumble (20–125 Hz)',
            self::RmsDbfs => 'Raw microphone level',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LAeq => 'The steady, typical loudness over the period, weighted the way human ears hear. This is the main number to compare.',
            self::LAFmax => 'The single loudest instant in the period: a slammed door, a bark, a shout. Always higher than the average.',
            self::LCeq => 'Like the average, but counting bass fully. Much higher than the average loudness means the sound is bass-heavy (music thump, idling engines).',
            self::LCpeak => 'The highest instantaneous pressure, for very short sharp sounds like bangs and impacts.',
            self::LowFrequencyLeq => 'Only the deep bass you feel through walls and windows (subwoofers, engines, HVAC hum).',
            self::RmsDbfs => 'How hard the microphone is driven, relative to its maximum. Not a real loudness value; shown when no calibration is available.',
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
        return $this->plainName().' — '.$this->getLabel().' ('.$this->unit().')';
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
