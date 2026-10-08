<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MicrophoneState: string implements HasLabel
{
    case Ok = 'ok';
    case Disconnected = 'disconnected';
    case Error = 'error';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Disconnected => 'Disconnected',
            self::Error => 'Error',
            self::Unknown => 'Unknown',
        };
    }
}
