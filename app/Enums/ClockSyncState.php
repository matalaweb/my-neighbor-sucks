<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ClockSyncState: string implements HasLabel
{
    case Synchronized = 'synchronized';
    case Unsynchronized = 'unsynchronized';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Synchronized => 'Synchronized',
            self::Unsynchronized => 'Unsynchronized',
            self::Unknown => 'Unknown',
        };
    }
}
