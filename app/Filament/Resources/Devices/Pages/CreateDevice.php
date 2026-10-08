<?php

namespace App\Filament\Resources\Devices\Pages;

use App\Filament\Resources\Devices\DeviceResource;
use App\Services\Audit\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateDevice extends CreateRecord
{
    protected static string $resource = DeviceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...$data,
            'status' => 'active',
            'reporting_interval_seconds' => config('noise.device_defaults.reporting_interval_seconds'),
            'heartbeat_interval_seconds' => config('noise.device_defaults.heartbeat_interval_seconds'),
        ];
    }

    protected function afterCreate(): void
    {
        app(AuditLogger::class)->record('device.created', $this->record);
    }

    protected function getRedirectUrl(): string
    {
        return DeviceResource::getUrl('view', ['record' => $this->record]);
    }
}
