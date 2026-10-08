<?php

namespace App\Filament\Resources\EvidenceExports;

use App\Enums\ExportKind;
use App\Enums\ExportStatus;
use App\Filament\Resources\EvidenceExports\Pages\ListEvidenceExports;
use App\Jobs\BuildExportJob;
use App\Models\EvidenceExport;
use App\Services\Audit\AuditLogger;
use App\Services\Exports\ExportDownloads;
use App\Support\LocalTime;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Number;
use UnitEnum;

class EvidenceExportResource extends Resource
{
    protected static ?string $model = EvidenceExport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $slug = 'reports';

    protected static ?string $modelLabel = 'report';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('requested_at')->label('Requested')->formatStateUsing(fn ($state, EvidenceExport $record): string => LocalTime::displayShort($state, $record->property->timezone))
                    ->description(fn (EvidenceExport $record): string => 'by '.($record->requester?->name ?? 'unknown')),
                TextColumn::make('kind')->badge()->color('gray'),
                TextColumn::make('title')->placeholder('—')->description(fn (EvidenceExport $record): string => self::scopeLabel($record)),
                TextColumn::make('status')->badge()->color(fn (ExportStatus $state): string => match ($state) {
                    ExportStatus::Ready => 'success',
                    ExportStatus::Failed => 'danger',
                    ExportStatus::Expired => 'gray',
                    default => 'warning',
                })->description(fn (EvidenceExport $record): ?string => $record->status === ExportStatus::Failed ? str($record->failure_reason)->limit(120)->toString() : null),
                TextColumn::make('size')->state(fn (EvidenceExport $record): string => $record->byte_size ? Number::fileSize($record->byte_size) : ($record->estimated_bytes ? '≈ '.Number::fileSize($record->estimated_bytes).' est.' : '—')),
                TextColumn::make('expires_at')->label('Expires')->formatStateUsing(fn ($state): string => LocalTime::age($state))->placeholder('—'),
                TextColumn::make('export_sha256')->label('SHA-256')->limit(12)->fontFamily('mono')->copyable()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(ExportStatus::class),
                SelectFilter::make('kind')->options(ExportKind::class),
            ])
            ->recordActions([
                Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (EvidenceExport $record): bool => $record->isDownloadable() && auth()->user()->can('download', $record))
                    ->action(fn (EvidenceExport $record, $livewire) => $livewire->redirect(app(ExportDownloads::class)->issueUrl(auth()->user(), $record))),
                Action::make('retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (EvidenceExport $record): bool => $record->status === ExportStatus::Failed && auth()->user()->canExport($record->account_id))
                    ->requiresConfirmation()
                    ->modalDescription('Rebuilds from the same frozen selection.')
                    ->action(function (EvidenceExport $record): void {
                        $record->forceFill(['status' => ExportStatus::Pending, 'failure_reason' => null])->save();
                        app(AuditLogger::class)->record('export.retried', $record);
                        BuildExportJob::dispatch($record->id);
                    }),
            ]);
    }

    public static function scopeLabel(EvidenceExport $record): string
    {
        $scope = $record->scope;

        return ($scope['type'] ?? null) === 'events'
            ? count($scope['event_uuids'] ?? []).' selected event(s)'
            : 'Local dates '.($scope['from_date'] ?? '?').' – '.($scope['to_date'] ?? '?').' ('.$record->property->timezone.')';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEvidenceExports::route('/'),
        ];
    }
}
