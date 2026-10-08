<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CompletenessState: string implements HasLabel
{
    case Pending = 'pending';
    case Complete = 'complete';
    case Partial = 'partial';
    case Unavailable = 'unavailable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Complete => 'Complete',
            self::Partial => 'Partial',
            self::Unavailable => 'Unavailable',
        };
    }
}
