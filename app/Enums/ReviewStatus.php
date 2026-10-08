<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReviewStatus: string implements HasLabel
{
    case Unreviewed = 'unreviewed';
    case ConfirmedDisturbance = 'confirmed_disturbance';
    case HouseholdNoise = 'household_noise';
    case Uncertain = 'uncertain';
    case Dismissed = 'dismissed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unreviewed => 'Unreviewed',
            self::ConfirmedDisturbance => 'Confirmed disturbance',
            self::HouseholdNoise => 'Household / garage noise',
            self::Uncertain => 'Uncertain',
            self::Dismissed => 'Dismissed',
        };
    }
}
