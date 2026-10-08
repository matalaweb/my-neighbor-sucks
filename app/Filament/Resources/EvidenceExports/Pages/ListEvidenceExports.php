<?php

namespace App\Filament\Resources\EvidenceExports\Pages;

use App\Enums\ExportKind;
use App\Filament\Resources\EvidenceExports\EvidenceExportResource;
use App\Models\NoiseEvent;
use App\Models\Property;
use App\Services\Exports\RequestEvidenceExport;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;

class ListEvidenceExports extends ListRecords
{
    protected static string $resource = EvidenceExportResource::class;

    protected ?string $subheading = 'Reports describe a DIY monitoring system and its actual equipment and calibration status. They are never sent anywhere automatically; downloads are private, audited, and expire after 7 days.';

    protected function getHeaderActions(): array
    {
        $account = Filament::getTenant();
        $user = auth()->user();

        return [
            Action::make('create')
                ->label('New report')
                ->icon('heroicon-o-plus')
                ->visible($user->canExport($account))
                ->modalWidth('2xl')
                ->schema([
                    Select::make('property_id')->label('Property')->options(Property::query()->where('account_id', $account->getKey())->pluck('name', 'id'))->default(Property::query()->where('account_id', $account->getKey())->value('id'))->required()->live(),
                    Radio::make('kind')->options(ExportKind::class)->default(ExportKind::PdfSummary->value)->required()
                        ->descriptions([
                            ExportKind::PdfSummary->value => 'Coverage, equipment, calibration status, event table, charts, review notes, limitations.',
                            ExportKind::CsvMeasurements->value => 'One-second readings (or explicitly labelled coarser data) for the range.',
                            ExportKind::EvidenceBundle->value => 'ZIP: PDF, CSVs, source revisions, annotations, snapshots, verified original audio, provenance, and a SHA-256 manifest.',
                        ]),
                    Radio::make('scope')->options(['range' => 'Date range', 'events' => 'Selected events'])->default('range')->inline()->live(),
                    DatePicker::make('from_date')->label('From (local date)')->visible(fn (Get $get): bool => $get('scope') === 'range')->required(fn (Get $get): bool => $get('scope') === 'range')->default(CarbonImmutable::now()->subDays(6)->toDateString()),
                    DatePicker::make('to_date')->label('To (local date, inclusive)')->visible(fn (Get $get): bool => $get('scope') === 'range')->required(fn (Get $get): bool => $get('scope') === 'range')->default(CarbonImmutable::now()->toDateString()),
                    Select::make('event_uuids')->label('Events')->multiple()->searchable()
                        ->visible(fn (Get $get): bool => $get('scope') === 'events')
                        ->required(fn (Get $get): bool => $get('scope') === 'events')
                        ->options(fn (Get $get): array => NoiseEvent::query()->where('account_id', $account->getKey())->where('property_id', $get('property_id'))->latest('started_at')->limit(500)->get()
                            ->mapWithKeys(fn (NoiseEvent $event): array => [$event->uuid => LocalTime::displayShort($event->started_at, $event->property->timezone).' · '.$event->review_status->getLabel()])->all()),
                    TextInput::make('title')->maxLength(255),
                    Toggle::make('override')->label('Override the 31-day / 500-event limit (owner only)')->visible($user->canManage($account))
                        ->helperText('Large bundles can take a long time and use significant storage.'),
                ])
                ->action(function (array $data) use ($account, $user): void {
                    $property = Property::query()->where('account_id', $account->getKey())->findOrFail($data['property_id']);
                    $scope = $data['scope'] === 'events'
                        ? ['type' => 'events', 'event_uuids' => array_values($data['event_uuids'] ?? [])]
                        : ['type' => 'range', 'from_date' => CarbonImmutable::parse($data['from_date'])->toDateString(), 'to_date' => CarbonImmutable::parse($data['to_date'])->toDateString()];

                    try {
                        $export = app(RequestEvidenceExport::class)->handle($user, $property, ($data['kind'] instanceof ExportKind ? $data['kind'] : ExportKind::from($data['kind'])), $scope, [], (bool) ($data['override'] ?? false), $data['title'] ?? null);
                    } catch (ValidationException $exception) {
                        Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();

                        return;
                    }

                    $large = $export->estimated_bytes > (int) config('noise.exports.large_bundle_warning_bytes');
                    Notification::make()->title('Report queued.')->body('Estimated size '.Number::fileSize($export->estimated_bytes ?? 0).($large ? ' — this is a large bundle.' : ''))->{$large ? 'warning' : 'success'}()->send();
                }),
        ];
    }
}
