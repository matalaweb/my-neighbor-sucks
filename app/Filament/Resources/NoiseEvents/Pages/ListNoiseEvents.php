<?php

namespace App\Filament\Resources\NoiseEvents\Pages;

use App\Filament\Resources\NoiseEvents\NoiseEventResource;
use Filament\Resources\Pages\ListRecords;

class ListNoiseEvents extends ListRecords
{
    protected static string $resource = NoiseEventResource::class;

    protected ?string $subheading = 'Events are detected by the device. Review labels record what a person observed or suspects; they do not prove a source or a legal violation.';
}
