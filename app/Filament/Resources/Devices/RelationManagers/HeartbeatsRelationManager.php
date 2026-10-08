<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use App\Models\DeviceHeartbeat;
use App\Support\LocalTime;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

/**
 * Recent detailed heartbeats (retained 7 days by default).
 */
class HeartbeatsRelationManager extends RelationManager
{
    protected static string $relationship = 'heartbeats';

    protected static ?string $title = 'Health history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $tz = $this->getOwnerRecord()->property->timezone;

        return $table
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('received_at')->label('Received (server)')->formatStateUsing(fn ($state): string => LocalTime::display($state, $tz)),
                TextColumn::make('sent_at')->label('Sent (device clock)')->formatStateUsing(fn ($state): string => LocalTime::display($state, $tz))->toggleable(),
                TextColumn::make('microphone_state')->badge(),
                TextColumn::make('clock_sync_state')->label('Clock')->badge()
                    ->color(fn (DeviceHeartbeat $record): string => $record->hasClockProblem() ? 'danger' : 'success')
                    ->description(fn (DeviceHeartbeat $record): ?string => $record->clock_offset_ms === null ? null : $record->clock_offset_ms.' ms'),
                TextColumn::make('free_disk_bytes')->label('Free disk')->formatStateUsing(fn ($state): string => $state === null ? '—' : Number::fileSize($state)),
                TextColumn::make('queued_measurement_count')->label('Queued readings')->numeric(),
                TextColumn::make('pending_audio_bytes')->label('Pending audio')->formatStateUsing(fn ($state): string => $state === null ? '—' : Number::fileSize($state)),
                TextColumn::make('applied_config_revision')->label('Applied config')->prefix('r')->placeholder('—'),
                TextColumn::make('agent_version')->toggleable(),
                TextColumn::make('last_capture_error')->limit(40)->placeholder('—')->toggleable(),
            ]);
    }
}
