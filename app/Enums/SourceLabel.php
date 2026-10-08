<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SourceLabel: string implements HasLabel
{
    case EngineLike = 'engine_like';
    case OwnGarageDoor = 'own_garage_door';
    case HouseholdActivity = 'household_activity';
    case Other = 'other';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::EngineLike => 'Engine-like noise',
            self::OwnGarageDoor => 'Own garage door',
            self::HouseholdActivity => 'Household activity',
            self::Other => 'Other',
            self::Unknown => 'Unknown',
        };
    }
}
