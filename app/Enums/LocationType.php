<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum LocationType: string implements HasLabel
{
    case Indoor = 'indoor';
    case Outdoor = 'outdoor';
    case SemiEnclosed = 'semi_enclosed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Indoor => 'Indoor',
            self::Outdoor => 'Outdoor',
            self::SemiEnclosed => 'Semi-enclosed (garage, porch)',
        };
    }
}
