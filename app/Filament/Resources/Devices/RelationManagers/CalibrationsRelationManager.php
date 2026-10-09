<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use App\Models\DeviceCalibration;
use App\Services\Devices\ProvenanceRecords;
use App\Services\Storage\AttachmentStore;
use App\Support\LocalTime;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Calibration records. "Calibrated" means a documented measurement chain,
 * not a certified instrument; no accuracy or uncertainty value is invented.
 * The device registers its calibrations (with its frequency-response file);
 * owners add field checks and supporting documents.
 */
class CalibrationsRelationManager extends RelationManager
{
    protected static string $relationship = 'calibrations';

    protected static ?string $title = 'Calibration';

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('uuid')->label('Calibration ID')->copyable(),
            TextEntry::make('channel'),
            TextEntry::make('revision'),
            TextEntry::make('source')->badge(),
            TextEntry::make('calibration_state')->badge(),
            TextEntry::make('reference_method'),
            TextEntry::make('reference_device')->placeholder('—'),
            TextEntry::make('reference_level_db')->suffix(' dB')->placeholder('—'),
            TextEntry::make('reference_frequency_hz')->suffix(' Hz')->placeholder('—'),
            TextEntry::make('sensitivity_mv_per_pa')->suffix(' mV/Pa')->placeholder('—'),
            TextEntry::make('sensitivity_dbfs_at_94db')->label('Level at 94 dB SPL')->suffix(' dBFS')->placeholder('—'),
            TextEntry::make('gain_configuration')->placeholder('—'),
            TextEntry::make('application_method')->placeholder('—'),
            TextEntry::make('performed_at')->formatStateUsing(fn ($state, DeviceCalibration $record): string => LocalTime::display($state, $record->device->property->timezone))->placeholder('—'),
            TextEntry::make('performed_by')->placeholder('—'),
            TextEntry::make('correction_metadata')->label('Correction metadata')->state(fn (DeviceCalibration $record): string => $record->correction_metadata ? json_encode($record->correction_metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '—')->columnSpanFull(),
            TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
            TextEntry::make('content_hash')->label('SHA-256')->fontFamily('mono')->copyable()->columnSpanFull(),
            RepeatableEntry::make('attachments')->schema([
                TextEntry::make('purpose')->badge(),
                TextEntry::make('original_filename'),
                TextEntry::make('sha256')->fontFamily('mono'),
            ])->columns(3)->columnSpanFull()->placeholder('No attachments'),
            RepeatableEntry::make('fieldChecks')->label('Field checks')->schema([
                TextEntry::make('checked_at')->dateTime(),
                TextEntry::make('reference_source'),
                TextEntry::make('expected_level_db')->suffix(' dB')->placeholder('—'),
                TextEntry::make('measured_level_db')->suffix(' dB')->placeholder('—'),
                TextEntry::make('passed')->badge()->formatStateUsing(fn ($state): string => $state === null ? 'not judged' : ($state ? 'within owner tolerance' : 'outside owner tolerance')),
                TextEntry::make('notes')->placeholder('—'),
            ])->columns(3)->columnSpanFull()->placeholder('No field checks recorded'),
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
                TextColumn::make('calibration_state')->badge(),
                TextColumn::make('reference_method'),
                TextColumn::make('field_checks_count')->counts('fieldChecks')->label('Field checks'),
                TextColumn::make('attachments_count')->counts('attachments')->label('Files'),
                TextColumn::make('uuid')->label('ID')->limit(13)->fontFamily('mono')->copyable(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('fieldCheck')
                    ->label('Add field check')
                    ->icon('heroicon-o-check-badge')
                    ->visible(fn (): bool => $this->canManage())
                    ->schema([
                        DateTimePicker::make('checked_at')->default(now())->required()->seconds(false),
                        TextInput::make('reference_source')->required(),
                        TextInput::make('expected_level_db')->numeric()->suffix('dB'),
                        TextInput::make('measured_level_db')->numeric()->suffix('dB'),
                        Select::make('passed')->options([1 => 'Within my tolerance', 0 => 'Outside my tolerance'])->placeholder('Not judged'),
                        Textarea::make('notes')->rows(2),
                    ])
                    ->action(fn (DeviceCalibration $record, array $data) => app(ProvenanceRecords::class)->addFieldCheck($record, [...$data, 'passed' => $data['passed'] === null ? null : (bool) $data['passed']], auth()->user())),
                Action::make('attach')
                    ->label('Attach file')
                    ->icon('heroicon-o-paper-clip')
                    ->visible(fn (): bool => $this->canManage())
                    ->schema([
                        // Frequency-response files come from the device with its calibration record.
                        Select::make('purpose')->options([
                            'certificate' => 'Calibration certificate / documentation',
                            'photo' => 'Photo',
                            'other' => 'Other',
                        ])->required(),
                        FileUpload::make('file')->storeFiles(false)->required()->maxSize(20480),
                    ])
                    ->action(function (DeviceCalibration $record, array $data): void {
                        /** @var TemporaryUploadedFile $file */
                        $file = $data['file'];
                        app(AttachmentStore::class)->store($record, $file->getRealPath(), $file->getClientOriginalName(), $file->getMimeType(), $data['purpose'], auth()->user());
                        $file->delete();
                        Notification::make()->title('File stored with SHA-256 checksum.')->success()->send();
                    }),
            ]);
    }

    private function canManage(): bool
    {
        return auth()->user()->canManage($this->getOwnerRecord()->account_id);
    }
}
