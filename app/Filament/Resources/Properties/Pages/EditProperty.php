<?php

namespace App\Filament\Resources\Properties\Pages;

use App\Filament\Resources\Properties\PropertyResource;
use App\Services\Audit\AuditLogger;
use Filament\Resources\Pages\EditRecord;

class EditProperty extends EditRecord
{
    protected static string $resource = PropertyResource::class;

    protected function afterSave(): void
    {
        app(AuditLogger::class)->record('property.updated', $this->record, ['changed' => array_keys($this->record->getChanges())]);
    }
}
