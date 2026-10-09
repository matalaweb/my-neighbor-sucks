<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use App\Models\MeasurementProfile;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Immutable measurement profiles: microphone, gain, processing versions,
 * calibration state, supported metrics, and band definitions. Read-only: the
 * device registers its own profiles; owner-created records remain as history.
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
            TextEntry::make('source')->badge(),
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
                TextColumn::make('source')->badge(),
                TextColumn::make('microphone_model'),
                TextColumn::make('calibration_state')->badge(),
                TextColumn::make('gain_db')->suffix(' dB')->placeholder('—'),
                TextColumn::make('agent_processing_version')->label('Agent processing'),
                TextColumn::make('uuid')->label('ID')->limit(13)->fontFamily('mono')->copyable(),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
