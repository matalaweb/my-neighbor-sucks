<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ExportStatus: string implements HasLabel
{
    case Pending = 'pending';
    case Building = 'building';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Building => 'Building',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
            self::Expired => 'Expired',
        };
    }
}
