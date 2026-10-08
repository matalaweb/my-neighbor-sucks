<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ConfigurationAckStatus: string implements HasLabel
{
    case Applied = 'applied';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Applied => 'Applied',
            self::Rejected => 'Rejected',
        };
    }
}
