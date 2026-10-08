<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Calibration state of a measurement chain. "Calibrated" means a documented
 * measurement chain, not a certified instrument (spec §4).
 */
enum CalibrationState: string implements HasColor, HasDescription, HasLabel
{
    case Uncalibrated = 'uncalibrated';
    case Estimated = 'estimated';
    case Calibrated = 'calibrated';

    public function getLabel(): string
    {
        return match ($this) {
            self::Uncalibrated => 'Uncalibrated',
            self::Estimated => 'Estimated SPL',
            self::Calibrated => 'Calibrated (documented chain)',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Uncalibrated => 'Only digital levels (dBFS) are meaningful; absolute sound pressure levels are not reported.',
            self::Estimated => 'Absolute levels are estimates from an undocumented or partial calibration chain. Treat as approximate.',
            self::Calibrated => 'Absolute levels come from a documented calibration chain. This is not a certified or regulatory instrument.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Uncalibrated => 'gray',
            self::Estimated => 'warning',
            self::Calibrated => 'success',
        };
    }

    public function allowsAbsoluteLevels(): bool
    {
        return $this !== self::Uncalibrated;
    }
}
