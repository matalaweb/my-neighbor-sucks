<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use App\Enums\ConfigurationAckStatus;
use App\Enums\Metric;
use App\Models\Device;
use App\Models\DeviceConfiguration;
use App\Services\Devices\DeviceConfigurationService;
use App\Support\LocalTime;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Declarative, versioned configuration. Desired and applied revisions are
 * tracked separately; rollback publishes a new revision.
 */
class ConfigurationsRelationManager extends RelationManager
{
    protected static string $relationship = 'configurations';

    protected static ?string $title = 'Configuration';

    public function table(Table $table): Table
    {
        /** @var Device $device */
        $device = $this->getOwnerRecord();

        return $table
            ->defaultSort('revision', 'desc')
            ->description(new HtmlString('Desired <strong>r'.e($device->desired_config_revision ?? '—').'</strong> · applied <strong>r'.e($device->applied_config_revision ?? '—').'</strong>'.($device->desired_config_revision !== $device->applied_config_revision ? ' · <span class="text-warning-600">waiting for the device to acknowledge</span>' : '')))
            ->columns([
                TextColumn::make('revision')->prefix('r'),
                TextColumn::make('issued_at')->formatStateUsing(fn ($state): string => LocalTime::display($state, $device->property->timezone, false)),
                TextColumn::make('ack')->label('Device acknowledgment')->badge()
                    ->state(function (DeviceConfiguration $record) use ($device): string {
                        $ack = $record->acknowledgments()->latest('id')->first();

                        return match (true) {
                            $ack?->status === ConfigurationAckStatus::Applied => 'Applied',
                            $ack?->status === ConfigurationAckStatus::Rejected => 'Rejected',
                            $record->revision === $device->desired_config_revision => 'Pending',
                            default => 'Superseded',
                        };
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Applied' => 'success',
                        'Rejected' => 'danger',
                        'Pending' => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (DeviceConfiguration $record): ?string => $record->acknowledgments()->latest('id')->value('reason')),
                TextColumn::make('rollback_of_revision')->label('Rollback of')->prefix('r')->placeholder('—'),
                TextColumn::make('creator.name')->label('By')->placeholder('—'),
                TextColumn::make('content_hash')->label('SHA-256')->limit(12)->fontFamily('mono')->copyable(),
            ])
            ->headerActions([
                Action::make('publish')
                    ->label('Publish new revision')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (): bool => auth()->user()->canManage($device->account_id) && ! $device->isArchived())
                    ->modalWidth('5xl')
                    ->fillForm(function () use ($device): array {
                        $service = app(DeviceConfigurationService::class);
                        $latest = $device->latestConfiguration();

                        return ['data' => $latest ? $service->settingsFromDocument($latest->document) : $service->defaults()];
                    })
                    ->schema($this->configurationSchema($device))
                    ->action(function (array $data) use ($device): void {
                        $configuration = app(DeviceConfigurationService::class)->publish($device, $data['data'], auth()->user(), $data['notes'] ?? null);
                        $warnings = app(DeviceConfigurationService::class)->validate($device, $configuration->document)['warnings'];

                        Notification::make()
                            ->title('Published configuration r'.$configuration->revision.'; pending until the device acknowledges it.')
                            ->body(implode(' ', $warnings) ?: null)
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('document')
                    ->label('View')
                    ->icon('heroicon-o-code-bracket')
                    ->modalSubmitAction(false)
                    ->modalWidth('4xl')
                    ->modalContent(fn (DeviceConfiguration $record): HtmlString => new HtmlString(
                        '<p class="mb-2 text-xs">SHA-256 <code>'.e($record->content_hash).'</code></p>'
                        .'<pre class="max-h-[32rem] overflow-auto rounded bg-gray-100 p-3 text-xs dark:bg-gray-800">'.e(json_encode($record->document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)).'</pre>'
                        .'<h3 class="mt-4 text-sm font-semibold">Acknowledgments</h3><ul class="text-xs">'
                        .$record->acknowledgments()->orderBy('id')->get()->map(fn ($ack): string => '<li>'.e($ack->status->getLabel().' · reported applied '.LocalTime::display($ack->reported_applied_at, 'UTC').' · received '.LocalTime::display($ack->received_at, 'UTC').($ack->reason ? ' · '.$ack->reason : '')).'</li>')->implode('')
                        .'</ul>'
                    )),
                Action::make('rollback')
                    ->label('Roll back to this')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (DeviceConfiguration $record): bool => auth()->user()->canManage($device->account_id) && $record->revision !== $device->desired_config_revision)
                    ->requiresConfirmation()
                    ->modalDescription('Creates a new revision that copies this revision\'s values. History is kept.')
                    ->action(function (DeviceConfiguration $record) use ($device): void {
                        $new = app(DeviceConfigurationService::class)->rollback($device, $record, auth()->user());
                        Notification::make()->title('Published r'.$new->revision.' (copy of r'.$record->revision.').')->success()->send();
                    }),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    private function configurationSchema(Device $device): array
    {
        $profiles = $device->measurementProfiles()->orderByDesc('id')->get()->mapWithKeys(fn ($p): array => [$p->uuid => $p->label()])->all();
        $deployments = $device->deployments()->orderByDesc('revision')->get()->mapWithKeys(fn ($d): array => [$d->uuid => $d->label()])->all();
        $calibrations = $device->calibrations()->orderByDesc('id')->get()->mapWithKeys(fn ($c): array => [$c->uuid => $c->label()])->all();
        $metricOptions = collect(Metric::cases())->mapWithKeys(fn (Metric $m): array => [$m->value => $m->labelWithUnit()])->all();

        return [
            Section::make('Reporting')->schema([
                TextInput::make('data.reporting_interval_seconds')->label('Upload interval')->numeric()->suffix('s')->required()->minValue(5)->maxValue(300),
                TextInput::make('data.heartbeat_interval_seconds')->label('Heartbeat interval')->numeric()->suffix('s')->required()->minValue(10)->maxValue(3600),
            ])->columns(2),
            Section::make('Channels')->schema([
                Repeater::make('data.channels')->label('')->schema([
                    Select::make('measurement_profile_id')->label('Measurement profile')->options($profiles)->required(),
                    Select::make('deployment_id')->label('Placement')->options($deployments)->required(),
                    Select::make('calibration_id')->label('Calibration')->options($calibrations)->placeholder('None (uncalibrated)'),
                    Toggle::make('enabled')->default(true),
                    CheckboxList::make('metrics')->options($metricOptions)->columns(3)->required(),
                    Toggle::make('bands_enabled')->label('Third-octave bands'),
                ])->columns(3)->minItems(1)->defaultItems(1),
            ]),
            Section::make('Recording')->schema([
                Toggle::make('data.recording_enabled')->label('Record event clips'),
                Select::make('data.recording_format')->options(['audio/flac' => 'FLAC (mono)', 'audio/wav' => 'PCM WAV (mono)'])->required(),
                TextInput::make('data.pre_roll_seconds')->numeric()->suffix('s')->required(),
                TextInput::make('data.post_roll_seconds')->numeric()->suffix('s')->required(),
                TextInput::make('data.max_segment_duration_seconds')->label('Max segment')->numeric()->suffix('s')->required(),
            ])->columns(5),
            Section::make('Detection rules')
                ->description('Thresholds are your choice for this installation — not legal or universal disturbance limits. Run an observation period before relying on a threshold.')
                ->schema([
                    TextInput::make('data.detection_rule_version')->label('Rule set version')->required(),
                    DateTimePicker::make('data.observation_period_until')->label('Observation period until')->seconds(false),
                    Toggle::make('data.absolute_enabled')->label('Absolute rule'),
                    Select::make('data.absolute_metric')->options($metricOptions),
                    TextInput::make('data.absolute_level_db')->label('Level')->numeric()->suffix('dB'),
                    Toggle::make('data.relative_enabled')->label('Baseline-relative rule'),
                    Select::make('data.relative_metric')->options($metricOptions),
                    TextInput::make('data.relative_delta_db')->label('Above baseline by')->numeric()->suffix('dB'),
                    TextInput::make('data.baseline_window_seconds')->numeric()->suffix('s'),
                    TextInput::make('data.min_event_duration_ms')->numeric()->suffix('ms'),
                    TextInput::make('data.merge_gap_ms')->numeric()->suffix('ms'),
                ])->columns(3),
            Section::make('Local retention on the Pi')->schema([
                TextInput::make('data.local_measurement_retention_days')->numeric()->suffix('days'),
                TextInput::make('data.local_audio_retention_days')->numeric()->suffix('days'),
                TextInput::make('data.max_local_disk_percent')->numeric()->suffix('%'),
            ])->columns(3),
            TextInput::make('notes')->label('Change note'),
        ];
    }
}
