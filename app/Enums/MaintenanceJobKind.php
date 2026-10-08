<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MaintenanceJobKind: string implements HasLabel
{
    case RollupMinute = 'rollup_minute';
    case RollupHour = 'rollup_hour';
    case EventSnapshot = 'event_snapshot';
    case VerifyRecording = 'verify_recording';
    case PurgeRecording = 'purge_recording';
    case DeleteObject = 'delete_object';

    public function getLabel(): string
    {
        return match ($this) {
            self::RollupMinute => 'Minute rollup rebuild',
            self::RollupHour => 'Hour rollup rebuild',
            self::EventSnapshot => 'Event measurement snapshot',
            self::VerifyRecording => 'Recording verification',
            self::PurgeRecording => 'Recording purge',
            self::DeleteObject => 'Object deletion',
        };
    }
}
