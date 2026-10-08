<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use App\Enums\CalibrationState;
use App\Enums\Metric;
use App\Models\MeasurementProfile;
use App\Services\Devices\ProvenanceRecords;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * Immutable measurement profiles: microphone, gain, processing versions,
 * calibration state, supported metrics, and band definitions.
 */
class ProfilesRelationManager extends RelationManager
{
    protected static string $relationship = 'measurementProfiles';

    protected static ?string $title = 'Measurement profiles';

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('uuid')->label('Profile ID')->copyable(),
            TextEntry::make('channel'),
            TextEntry::make('revision'),
            TextEntry::make('calibration_state')->badge(),
            TextEntry::make('microphone_model'),
            TextEntry::make('microphone_serial')->placeholder('—'),
            TextEntry::make('audio_interface')->placeholder('—'),
            TextEntry::make('sample_rate_hz')->suffix(' Hz'),
            TextEntry::make('gain_db')->suffix(' dB')->placeholder('—'),
            TextEntry::make('gain_description')->placeholder('—'),
            TextEntry::make('weighting_implementation_version'),
            TextEntry::make('filter_implementation_version'),
            TextEntry::make('calibration_application_method')->placeholder('—'),
            TextEntry::make('agent_processing_version'),
            TextEntry::make('supported_metrics')->badge(),
            TextEntry::make('low_frequency_band')->state(fn (MeasurementProfile $record): string => $record->lowFrequencyBandLabel() ?? 'not defined'),
            TextEntry::make('bands')->label('Third-octave bands')->state(fn (MeasurementProfile $record): string => $record->supportedBandCenters() === [] ? 'none' : implode(', ', $record->supportedBandCenters()).' Hz ('.($record->band_definitions['weighting'] ?? 'Z').'-weighted)'),
            TextEntry::make('content_hash')->label('SHA-256')->fontFamily('mono')->copyable()->columnSpanFull(),
        ])->columns(3);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('channel'),
                TextColumn::make('revision')->prefix('r'),
                TextColumn::make('microphone_model'),
                TextColumn::make('calibration_state')->badge(),
                TextColumn::make('gain_db')->suffix(' dB')->placeholder('—'),
                TextColumn::make('agent_processing_version')->label('Agent processing'),
                TextColumn::make('uuid')->label('ID')->limit(13)->fontFamily('mono')->copyable(),
            ])
            ->headerActions([
                Action::make('revise')
                    ->label('New profile revision')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => auth()->user()->canManage($this->getOwnerRecord()->account_id))
                    ->modalWidth('4xl')
                    ->modalDescription('Profiles are immutable and apply prospectively. Historical readings keep their original profile.')
                    ->fillForm(fn (): array => $this->prefill())
                    ->schema([
                        Section::make('Microphone and gain')->schema([
                            TextInput::make('channel')->required()->default('mic-1')->regex('/^[A-Za-z0-9._-]{1,32}$/'),
                            TextInput::make('name')->maxLength(255),
                            TextInput::make('microphone_model')->required(),
                            TextInput::make('microphone_serial'),
                            TextInput::make('audio_interface'),
                            TextInput::make('sample_rate_hz')->numeric()->required()->default(48000),
                            TextInput::make('gain_db')->numeric()->suffix('dB'),
                            TextInput::make('gain_description'),
                        ])->columns(4),
                        Section::make('Processing')->schema([
                            TextInput::make('weighting_implementation_version')->required(),
                            TextInput::make('filter_implementation_version')->required(),
                            TextInput::make('agent_processing_version')->required(),
                            TextInput::make('calibration_application_method')->helperText('How the agent applies calibration (e.g. "sensitivity offset in dBFS→SPL").'),
                        ])->columns(2),
                        Section::make('Calibration state and metrics')->schema([
                            Radio::make('calibration_state')
                                ->options(CalibrationState::class)
                                ->descriptions(collect(CalibrationState::cases())->mapWithKeys(fn (CalibrationState $s): array => [$s->value => $s->getDescription()])->all())
                                ->required()
                                ->live(),
                            CheckboxList::make('supported_metrics')
                                ->options(collect(Metric::cases())->mapWithKeys(fn (Metric $m): array => [$m->value => $m->labelWithUnit()])->all())
                                ->disableOptionWhen(fn (string $value, Get $get): bool => $get('calibration_state') === CalibrationState::Uncalibrated->value && $value !== Metric::RmsDbfs->value)
                                ->helperText('Uncalibrated profiles report only dBFS; absolute SPL metrics stay null.')
                                ->required(),
                            Grid::make(2)->schema([
                                TextInput::make('low_frequency_lower_hz')->numeric()->suffix('Hz')->default(20),
                                TextInput::make('low_frequency_upper_hz')->numeric()->suffix('Hz')->default(125),
                            ]),
                            CheckboxList::make('band_centers_hz')
                                ->label('Third-octave bands (nominal centres)')
                                ->options(collect(config('noise.measurements.third_octave_centers_hz'))->mapWithKeys(fn ($c): array => [(string) $c => $c.' Hz'])->all())
                                ->columns(5)
                                ->helperText('These bands do not sum to the low-frequency band; the agent supplies that metric separately.'),
                        ]),
                    ])
                    ->action(function (array $data): void {
                        try {
                            app(ProvenanceRecords::class)->createProfile($this->getOwnerRecord(), $data, auth()->user());
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function prefill(): array
    {
        $latest = $this->getOwnerRecord()->measurementProfiles()->latest('id')->first();

        if ($latest === null) {
            return ['channel' => 'mic-1', 'sample_rate_hz' => 48000, 'calibration_state' => CalibrationState::Uncalibrated->value, 'supported_metrics' => ['rms_dbfs'], 'low_frequency_lower_hz' => 20, 'low_frequency_upper_hz' => 125];
        }

        return [
            ...$latest->only(['channel', 'name', 'microphone_model', 'microphone_serial', 'audio_interface', 'sample_rate_hz', 'gain_db', 'gain_description', 'weighting_implementation_version', 'filter_implementation_version', 'agent_processing_version', 'calibration_application_method', 'supported_metrics', 'low_frequency_lower_hz', 'low_frequency_upper_hz']),
            'calibration_state' => $latest->calibration_state->value,
            'band_centers_hz' => $latest->supportedBandCenters(),
        ];
    }
}
