<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RecordingStatus: string implements HasLabel
{
    case Pending = 'pending';
    case Uploaded = 'uploaded';
    case Verifying = 'verifying';
    case Verified = 'verified';
    case Failed = 'failed';
    case Missing = 'missing';
    case Purged = 'purged';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending upload',
            self::Uploaded => 'Uploaded',
            self::Verifying => 'Verifying',
            self::Verified => 'Verified',
            self::Failed => 'Failed verification',
            self::Missing => 'Missing',
            self::Purged => 'Purged',
        };
    }
}
