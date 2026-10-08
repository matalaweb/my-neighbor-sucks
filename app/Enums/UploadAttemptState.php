<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UploadAttemptState: string implements HasLabel
{
    case Issued = 'issued';
    case Completed = 'completed';
    case Verifying = 'verifying';
    case Verified = 'verified';
    case Failed = 'failed';
    case Superseded = 'superseded';
    case Expired = 'expired';
    case Cleaned = 'cleaned';

    public function getLabel(): string
    {
        return match ($this) {
            self::Issued => 'Issued',
            self::Completed => 'Completed (awaiting verification)',
            self::Verifying => 'Verifying',
            self::Verified => 'Verified',
            self::Failed => 'Failed',
            self::Superseded => 'Superseded',
            self::Expired => 'Expired',
            self::Cleaned => 'Staging cleaned',
        };
    }
}
