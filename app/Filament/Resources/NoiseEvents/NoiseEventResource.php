<?php

namespace App\Filament\Resources\NoiseEvents;

use App\Enums\CompletenessState;
use App\Enums\DetectionState;
use App\Enums\ExportKind;
use App\Enums\Metric;
use App\Enums\RecordingStatus;
use App\Enums\ReviewStatus;
use App\Enums\SourceLabel;
use App\Filament\Resources\EvidenceExports\EvidenceExportResource;
use App\Filament\Resources\NoiseEvents\Pages\ListNoiseEvents;
use App\Filament\Resources\NoiseEvents\Pages\ViewNoiseEvent;
use App\Models\NoiseEvent;
use App\Services\Exports\RequestEvidenceExport;
use App\Support\LocalTime;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class NoiseEventResource extends Resource
{
    protected static ?string $model = NoiseEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Events';

    protected static ?string $slug = 'events';

    protected static ?string $modelLabel = 'event';

    public static function getNavigationBadge(): ?string
    {
        $count = NoiseEvent::query()->where('account_id', Filament::getTenant()?->getKey())->where('review_status', ReviewStatus::Unreviewed)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Unreviewed events';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['device', 'property']))
            ->columns([
                TextColumn::make('started_at')
                    ->label('Start')
                    ->sortable()
                    ->formatStateUsing(fn ($state, NoiseEvent $record): string => LocalTime::displayShort($state, $record->property->timezone))
                    ->description(fn (NoiseEvent $record): string => $record->device->name.' · '.$record->channel),
                TextColumn::make('duration')
                    ->state(fn (NoiseEvent $record): string => $record->isOpen() ? 'open' : number_format($record->durationMs() / 1000, 1).' s'),
                TextColumn::make('trigger_value_db')
                    ->label('Trigger')
                    ->sortable()
                    ->formatStateUsing(fn ($state, NoiseEvent $record): string => $state === null ? '—' : number_format($state, 1).' '.Metric::from($record->trigger_metric)->unit().' '.Metric::from($record->trigger_metric)->getLabel())
                    ->description(fn (NoiseEvent $record): ?string => $record->levelAboveBaseline() === null ? null : $record->levelAboveBaseline().' dB above reported baseline'),
                TextColumn::make('review_status')->badge()->color(fn (ReviewStatus $state): string => match ($state) {
                    ReviewStatus::ConfirmedDisturbance => 'danger',
                    ReviewStatus::HouseholdNoise => 'info',
                    ReviewStatus::Uncertain => 'warning',
                    default => 'gray',
                }),
                TextColumn::make('source_label')->label('Source')->badge()->color('gray')->placeholder('—')
                    ->description(fn (NoiseEvent $record): ?string => $record->source_certainty?->getLabel()),
                TextColumn::make('recording_state')->label('Recording')->badge()->placeholder('none expected')
                    ->color(fn (?RecordingStatus $state): string => match ($state) {
                        RecordingStatus::Verified => 'success',
                        RecordingStatus::Failed, RecordingStatus::Missing => 'danger',
                        RecordingStatus::Purged, null => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('completeness_state')->label('Data')->badge()->color(fn (CompletenessState $state): string => match ($state) {
                    CompletenessState::Complete => 'success',
                    CompletenessState::Partial => 'warning',
                    CompletenessState::Unavailable => 'danger',
                    default => 'gray',
                }),
                TextColumn::make('quality_flags')->label('Quality')
                    ->formatStateUsing(fn (NoiseEvent $record): string => collect($record->qualityFlagList())->map->getLabel()->implode(', ') ?: '—'),
                IconColumn::make('keep')->boolean()->trueIcon('heroicon-s-lock-closed')->falseIcon('')->label('Keep'),
            ])
            ->filters([
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from')->label('From (local date)'),
                        DatePicker::make('until')->label('Until (local date)'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $tz = Filament::getTenant()->properties()->value('timezone') ?? config('noise.default_timezone');

                        if ($data['from'] ?? null) {
                            $query->where('started_at', '>=', CarbonImmutable::parse($data['from'], $tz)->startOfDay()->utc());
                        }

                        if ($data['until'] ?? null) {
                            $query->where('started_at', '<', CarbonImmutable::parse($data['until'], $tz)->addDay()->startOfDay()->utc());
                        }
                    }),
                SelectFilter::make('device_id')->label('Device')->relationship('device', 'name', fn (Builder $query) => $query->where('account_id', Filament::getTenant()->getKey())),
                SelectFilter::make('review_status')->options(ReviewStatus::class)->multiple(),
                SelectFilter::make('source_label')->label('Source label')->options(SourceLabel::class)->multiple(),
                SelectFilter::make('recording_state')->label('Recording')->options(RecordingStatus::class)->multiple(),
                SelectFilter::make('completeness_state')->label('Data completeness')->options(CompletenessState::class),
                SelectFilter::make('detection_state')->options(DetectionState::class),
                TernaryFilter::make('quality')->label('Quality flags')
                    ->trueLabel('Has quality flags')->falseLabel('No quality flags')
                    ->queries(true: fn (Builder $q) => $q->where('quality_flags', '>', 0), false: fn (Builder $q) => $q->where('quality_flags', 0)),
                Filter::make('level')
                    ->schema([TextInput::make('min_trigger_db')->label('Trigger level at least (dB, as reported)')->numeric()])
                    ->query(fn (Builder $query, array $data) => $query->when($data['min_trigger_db'] ?? null, fn ($q, $min) => $q->where('trigger_value_db', '>=', (float) $min))),
                TernaryFilter::make('keep'),
            ])
            ->filtersFormColumns(2)
            ->recordActions([ViewAction::make()])
            ->toolbarActions([
                BulkAction::make('export')
                    ->label('Create report / evidence bundle')
                    ->icon('heroicon-o-document-arrow-down')
                    ->visible(fn (): bool => auth()->user()->canExport(Filament::getTenant()))
                    ->schema([
                        Select::make('kind')->options(ExportKind::class)->default(ExportKind::EvidenceBundle->value)->required(),
                        TextInput::make('title')->maxLength(255),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $property = $records->first()->property;

                        if ($records->pluck('property_id')->unique()->count() > 1) {
                            Notification::make()->title('Select events from one property per export.')->danger()->send();

                            return;
                        }

                        try {
                            app(RequestEvidenceExport::class)->handle(auth()->user(), $property, ($data['kind'] instanceof ExportKind ? $data['kind'] : ExportKind::from($data['kind'])), [
                                'type' => 'events',
                                'event_uuids' => $records->pluck('uuid')->all(),
                            ], [], false, $data['title'] ?? null);
                        } catch (ValidationException $exception) {
                            Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Export queued.')->body('Track progress under Reports.')->success()
                            ->actions([Action::make('open')->url(EvidenceExportResource::getUrl())])->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNoiseEvents::route('/'),
            'view' => ViewNoiseEvent::route('/{record}'),
        ];
    }
}
