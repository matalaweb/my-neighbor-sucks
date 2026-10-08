<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DetectionState: string implements HasLabel
{
    case Open = 'open';
    case Finalized = 'finalized';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Finalized => 'Finalized',
        };
    }
}
