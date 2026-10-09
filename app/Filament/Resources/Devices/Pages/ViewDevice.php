<?php

namespace App\Filament\Resources\Devices\Pages;

use App\Enums\DeviceStatus;
use App\Filament\Resources\Devices\DeviceResource;
use App\Models\Device;
use App\Services\Audit\AuditLogger;
use App\Services\Devices\DeviceCredentialService;
use App\Services\Devices\DeviceSharingService;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

class ViewDevice extends ViewRecord
{
    protected static string $resource = DeviceResource::class;

    public function infolist(Schema $schema): Schema
    {
        $tz = fn (Device $record): string => $record->property->timezone;

        return $schema->components([
            Section::make('Status')->schema([
                TextEntry::make('uuid')->label('Device ID')->copyable(),
                TextEntry::make('property.name')->label('Property'),
                TextEntry::make('status')->badge(),
                TextEntry::make('contact')->label('Contact')->badge()
                    ->state(fn (Device $record): string => $record->isOnline() ? 'Reachable' : 'Offline')
                    ->helperText(fn (Device $record): string => 'Offline after '.$record->offlineAfterSeconds().' s without contact')
                    ->color(fn (Device $record): string => $record->isOnline() ? 'success' : 'danger'),
                TextEntry::make('last_contact_at')->label('Last contact (server receipt)')->formatStateUsing(fn ($state, Device $record): string => LocalTime::display($state, $tz($record)).' · '.LocalTime::age($state)),
                TextEntry::make('latest_capture_at')->label('Latest capture (device clock)')->formatStateUsing(fn ($state, Device $record): string => LocalTime::display($state, $tz($record)).' · '.LocalTime::age($state).($record->measurementsAreStale() ? ' · STALE' : '')),
                TextEntry::make('software_version')->label('Agent version')->placeholder('—'),
                TextEntry::make('public_dashboard')->label('Public dashboard')->badge()
                    ->state(fn (Device $record): string => $record->isPubliclyShared() ? 'Shared' : 'Not shared')
                    ->color(fn (Device $record): string => $record->isPubliclyShared() ? 'info' : 'gray')
                    ->helperText(fn (Device $record): ?string => $record->isPubliclyShared() ? 'Since '.LocalTime::display($record->shared_at, $tz($record), withSeconds: false) : null),
                TextEntry::make('config')->label('Configuration')->state(fn (Device $record): string => 'desired r'.($record->desired_config_revision ?? '—').' · applied r'.($record->applied_config_revision ?? '—').($record->desired_config_revision !== $record->applied_config_revision ? ' · PENDING' : '')),
            ])->columns(['default' => 1, 'sm' => 2, 'lg' => 4])->columnSpanFull(),
            Section::make('Latest heartbeat')->schema([
                TextEntry::make('latestHeartbeat.microphone_state')->label('Microphone')->badge()->placeholder('No heartbeat yet'),
                TextEntry::make('latestHeartbeat.clock_sync_state')->label('Clock')->badge()
                    ->color(fn (Device $record): string => $record->latestHeartbeat?->hasClockProblem() ? 'danger' : 'success'),
                TextEntry::make('latestHeartbeat.clock_offset_ms')->label('Estimated clock offset')->suffix(' ms')->placeholder('unknown'),
                TextEntry::make('disk')->label('Free disk')->state(fn (Device $record): string => $record->latestHeartbeat?->free_disk_bytes === null ? '—' : Number::fileSize($record->latestHeartbeat->free_disk_bytes).' of '.Number::fileSize($record->latestHeartbeat->total_disk_bytes ?? 0)),
                TextEntry::make('latestHeartbeat.queued_measurement_count')->label('Queued readings')->numeric()->placeholder('—'),
                TextEntry::make('audio')->label('Pending audio')->state(fn (Device $record): string => $record->latestHeartbeat === null ? '—' : ($record->latestHeartbeat->pending_audio_count ?? 0).' clips · '.Number::fileSize($record->latestHeartbeat->pending_audio_bytes ?? 0)),
                TextEntry::make('latestHeartbeat.oldest_pending_capture_at')->label('Oldest pending capture')->formatStateUsing(fn ($state): string => LocalTime::age($state))->placeholder('—'),
                TextEntry::make('latestHeartbeat.recent_dropped_intervals')->label('Recent dropped intervals')->placeholder('—'),
                TextEntry::make('latestHeartbeat.last_capture_error')->label('Last capture error')->placeholder('none')->columnSpanFull(),
            ])->columns(['default' => 1, 'sm' => 2, 'lg' => 4])->columnSpanFull(),
            Section::make('Onboarding')
                ->description('Install the credential and base URL on the Pi agent. The device API is documented in docs/openapi/device-api-v1.yaml.')
                ->schema([
                    TextEntry::make('api_base')->label('Device API base URL')->state(url('/api/v1/device'))->copyable(),
                ])
                ->collapsed(fn (Device $record): bool => $record->last_contact_at !== null)
                ->columnSpanFull(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            $this->publicDashboardActions(),
            Action::make('archive')
                ->label('Archive device')
                ->icon('heroicon-o-archive-box')
                ->color('danger')
                ->visible(fn (Device $record): bool => $record->status === DeviceStatus::Active && auth()->user()->canManage($record->account_id))
                ->requiresConfirmation()
                ->modalDescription('Archiving revokes all credentials and stops ingestion. All measurements, events, recordings, and provenance are preserved. This is not deletion.')
                ->action(function (Device $record): void {
                    DB::transaction(function () use ($record): void {
                        foreach ($record->credentials()->whereNull('revoked_at')->get() as $credential) {
                            app(DeviceCredentialService::class)->revoke($credential, auth()->user(), 'device_archived');
                        }

                        $record->forceFill(['status' => DeviceStatus::Archived, 'archived_at' => CarbonImmutable::now()])->save();
                        app(AuditLogger::class)->record('device.archived', $record);
                    });

                    Notification::make()->title('Device archived; evidence preserved.')->success()->send();
                }),
        ];
    }

    public function shareLinkAction(): Action
    {
        return Action::make('shareLink')
            ->label('View share link')
            ->icon('heroicon-o-link')
            ->visible(fn (Device $record): bool => $record->isPubliclyShared() && $this->canManageSharing($record))
            ->modalHeading('Public dashboard link')
            ->modalDescription('Anyone with this link can view this device\'s levels, charts, and events without signing in. Audio, notes, source labels, and the property address are never shown.')
            ->schema([
                TextEntry::make('share_url')
                    ->hiddenLabel()
                    ->state(fn (Device $record): ?string => $record->publicShareUrl())
                    ->fontFamily('mono')
                    ->copyable()
                    ->url(fn (Device $record): ?string => $record->publicShareUrl(), shouldOpenInNewTab: true),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Done');
    }

    private function publicDashboardActions(): ActionGroup
    {
        return ActionGroup::make([
            Action::make('share')
                ->label('Share publicly')
                ->icon('heroicon-o-globe-alt')
                ->visible(fn (Device $record): bool => ! $record->isPubliclyShared() && $this->canManageSharing($record))
                ->modalHeading('Share a public dashboard')
                ->modalDescription('Creates a secret link to a read-only dashboard for this device. Anyone with the link can view levels, charts, and events (with review status). You can regenerate or revoke the link at any time.')
                ->schema([
                    TextInput::make('public_title')
                        ->label('Public title')
                        ->maxLength(120)
                        ->placeholder('Noise monitor')
                        ->helperText('Shown instead of the device name. Leave empty for "Noise monitor".'),
                ])
                ->fillForm(fn (Device $record): array => ['public_title' => $record->public_title])
                ->modalSubmitActionLabel('Create link')
                ->action(function (array $data, Device $record): void {
                    $record->update(['public_title' => $data['public_title'] ?: null]);
                    app(DeviceSharingService::class)->share($record, auth()->user());
                    $this->replaceMountedAction('shareLink');
                }),
            $this->shareLinkAction(),
            Action::make('regenerateShareLink')
                ->label('Regenerate link')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (Device $record): bool => $record->isPubliclyShared() && $this->canManageSharing($record))
                ->requiresConfirmation()
                ->modalDescription('The current link stops working immediately. Anyone who should keep access needs the new link.')
                ->action(function (Device $record): void {
                    app(DeviceSharingService::class)->share($record, auth()->user());
                    $this->replaceMountedAction('shareLink');
                }),
            Action::make('stopSharing')
                ->label('Stop sharing')
                ->icon('heroicon-o-eye-slash')
                ->color('danger')
                ->visible(fn (Device $record): bool => $record->isPubliclyShared() && $this->canManageSharing($record))
                ->requiresConfirmation()
                ->modalDescription('The public link stops working immediately. Measurements and events are unaffected.')
                ->action(function (Device $record): void {
                    app(DeviceSharingService::class)->stop($record, auth()->user());
                    Notification::make()->title('Public dashboard link revoked.')->success()->send();
                }),
        ])
            ->label('Public dashboard')
            ->icon('heroicon-o-globe-alt')
            ->button()
            ->color('gray');
    }

    private function canManageSharing(Device $record): bool
    {
        return ! $record->isArchived() && auth()->user()->canManage($record->account_id);
    }
}
