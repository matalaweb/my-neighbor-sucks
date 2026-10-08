<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use App\Enums\LocationType;
use App\Models\DeviceDeployment;
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
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Immutable microphone placement revisions.
 */
class DeploymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'deployments';

    protected static ?string $title = 'Placement';

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('uuid')->label('Deployment ID')->copyable(),
            TextEntry::make('revision'),
            TextEntry::make('room')->placeholder('—'),
            TextEntry::make('location_type')->badge(),
            TextEntry::make('placement_description')->placeholder('—')->columnSpanFull(),
            TextEntry::make('mounting_notes')->placeholder('—')->columnSpanFull(),
            TextEntry::make('height_m')->suffix(' m')->placeholder('—'),
            TextEntry::make('orientation')->placeholder('—'),
            TextEntry::make('effective_at')->formatStateUsing(fn ($state, DeviceDeployment $record): string => LocalTime::display($state, $record->device->property->timezone)),
            TextEntry::make('content_hash')->label('SHA-256')->fontFamily('mono')->copyable(),
            TextEntry::make('creator.name')->label('Recorded by')->placeholder('—'),
            TextEntry::make('attachments_list')->label('Attachments')->state(fn (DeviceDeployment $record): string => $record->attachments->map(fn ($a) => $a->original_filename.' ('.$a->sha256.')')->implode(', ') ?: '—')->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('revision', 'desc')
            ->columns([
                TextColumn::make('revision')->prefix('r'),
                TextColumn::make('room'),
                TextColumn::make('location_type')->badge(),
                TextColumn::make('effective_at')->formatStateUsing(fn ($state): string => LocalTime::display($state, $this->getOwnerRecord()->property->timezone, false)),
                TextColumn::make('uuid')->label('ID')->limit(13)->fontFamily('mono')->copyable(),
            ])
            ->headerActions([
                Action::make('revise')
                    ->label('New placement revision')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => auth()->user()->canManage($this->getOwnerRecord()->account_id))
                    ->modalDescription('Placement revisions are immutable. Existing readings keep their original placement; reference the new revision in a new configuration.')
                    ->fillForm(fn (): array => $this->getOwnerRecord()->deployments()->latest('revision')->first()?->only(['room', 'location_type', 'placement_description', 'mounting_notes', 'height_m', 'orientation']) ?? ['location_type' => LocationType::Indoor])
                    ->schema([
                        TextInput::make('room')->maxLength(255),
                        Select::make('location_type')->options(LocationType::class)->required(),
                        Textarea::make('placement_description')->rows(2)->helperText('Where the microphone is and what it faces (e.g. "inside window, 2 m from driveway").'),
                        Textarea::make('mounting_notes')->rows(2),
                        TextInput::make('height_m')->numeric()->suffix('m'),
                        TextInput::make('orientation')->maxLength(255),
                        DateTimePicker::make('effective_at')->default(now())->timezone($this->getOwnerRecord()->property->timezone)->required()->seconds(false)->helperText('Readings captured before this time cannot reference this placement.'),
                    ])
                    ->action(fn (array $data) => app(ProvenanceRecords::class)->createDeployment($this->getOwnerRecord(), $data, auth()->user())),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('attach')
                    ->label('Attach file')
                    ->icon('heroicon-o-paper-clip')
                    ->visible(fn (): bool => auth()->user()->canManage($this->getOwnerRecord()->account_id))
                    ->schema([
                        Select::make('purpose')->options(['photo' => 'Placement photo', 'diagram' => 'Site diagram', 'other' => 'Other'])->required(),
                        FileUpload::make('file')->storeFiles(false)->required()->maxSize(20480),
                    ])
                    ->action(function (DeviceDeployment $record, array $data): void {
                        /** @var TemporaryUploadedFile $file */
                        $file = $data['file'];
                        app(AttachmentStore::class)->store($record, $file->getRealPath(), $file->getClientOriginalName(), $file->getMimeType(), $data['purpose'], auth()->user());
                        $file->delete();
                        Notification::make()->title('File stored with SHA-256 checksum.')->success()->send();
                    }),
            ]);
    }
}
