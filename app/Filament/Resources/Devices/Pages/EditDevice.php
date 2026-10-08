<?php

namespace App\Filament\Resources\Devices\Pages;

use App\Filament\Resources\Devices\DeviceResource;
use App\Services\Audit\AuditLogger;
use Filament\Resources\Pages\EditRecord;

class EditDevice extends EditRecord
{
    protected static string $resource = DeviceResource::class;

    protected function afterSave(): void
    {
        app(AuditLogger::class)->record('device.updated', $this->record, ['changed' => array_keys($this->record->getChanges())]);
    }

    protected function getRedirectUrl(): string
    {
        return DeviceResource::getUrl('view', ['record' => $this->record]);
    }
}
