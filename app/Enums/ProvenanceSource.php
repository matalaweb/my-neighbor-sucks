<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Who created a measurement profile or calibration record. Devices register
 * their own measurement chain; owner-created records are kept as history.
 */
enum ProvenanceSource: string implements HasColor, HasLabel
{
    case Owner = 'owner';
    case Device = 'device';

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Device => 'Device',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Owner => 'gray',
            self::Device => 'info',
        };
    }
}
