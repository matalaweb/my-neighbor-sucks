<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MaintenanceJobStatus: string implements HasLabel
{
    case Pending = 'pending';
    case Running = 'running';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Running => 'Running',
            self::Failed => 'Failed',
        };
    }
}
