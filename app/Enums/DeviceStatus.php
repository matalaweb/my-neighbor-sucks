<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DeviceStatus: string implements HasLabel
{
    case Active = 'active';
    case Archived = 'archived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }
}
