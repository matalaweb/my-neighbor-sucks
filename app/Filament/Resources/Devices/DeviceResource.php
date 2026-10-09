<?php

namespace App\Filament\Resources\Devices;

use App\Enums\DeviceStatus;
use App\Filament\Resources\Devices\Pages\CreateDevice;
use App\Filament\Resources\Devices\Pages\EditDevice;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Filament\Resources\Devices\Pages\ViewDevice;
use App\Filament\Resources\Devices\RelationManagers\CalibrationsRelationManager;
use App\Filament\Resources\Devices\RelationManagers\ConfigurationsRelationManager;
use App\Filament\Resources\Devices\RelationManagers\CredentialsRelationManager;
use App\Filament\Resources\Devices\RelationManagers\DeploymentsRelationManager;
use App\Filament\Resources\Devices\RelationManagers\HeartbeatsRelationManager;
use App\Filament\Resources\Devices\RelationManagers\ProfilesRelationManager;
use App\Models\Device;
use App\Support\LocalTime;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Equipment';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Device')->schema([
                TextInput::make('name')->required()->maxLength(255)->placeholder('Front window Pi'),
                Select::make('property_id')
                    ->label('Property')
                    ->relationship('property', 'name', fn ($query) => $query->where('account_id', Filament::getTenant()->getKey()))
                    ->required(),
                TextInput::make('public_title')
                    ->label('Public title')
                    ->maxLength(120)
                    ->placeholder('Noise monitor')
                    ->helperText('Shown instead of the device name on the public dashboard, if you share one.')
                    ->visibleOn('edit'),
            ])->columns(2),
            Section::make('Backfill import window')
                ->description('Readings older than '.config('noise.device_api.backfill_days').' days are rejected unless an owner enables an import window here.')
                ->schema([
                    DateTimePicker::make('import_window_starts_at')->label('Accept readings captured after')->seconds(false),
                    DateTimePicker::make('import_window_expires_at')->label('Window closes at')->seconds(false),
                ])
                ->columns(2)
                ->collapsed()
                ->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('property.name'),
                TextColumn::make('status')->badge()->color(fn (DeviceStatus $state): string => $state === DeviceStatus::Active ? 'success' : 'gray'),
                TextColumn::make('contact')
                    ->label('Contact')
                    ->badge()
                    ->state(fn (Device $record): string => $record->isOnline() ? 'Reachable' : 'Offline')
                    ->color(fn (string $state): string => $state === 'Reachable' ? 'success' : 'danger'),
                TextColumn::make('latest_capture_at')
                    ->label('Latest capture')
                    ->formatStateUsing(fn (Device $record): string => LocalTime::age($record->latest_capture_at).($record->measurementsAreStale() ? ' (stale)' : '')),
                TextColumn::make('config')
                    ->label('Config')
                    ->state(fn (Device $record): string => 'desired r'.($record->desired_config_revision ?? '—').' / applied r'.($record->applied_config_revision ?? '—')),
                TextColumn::make('software_version')->label('Agent')->toggleable(),
                IconColumn::make('shared')
                    ->label('Public')
                    ->state(fn (Device $record): bool => $record->isPubliclyShared())
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedGlobeAlt)
                    ->falseIcon(Heroicon::OutlinedMinus)
                    ->trueColor('info')
                    ->falseColor('gray')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(DeviceStatus::class)->default(DeviceStatus::Active->value),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            CredentialsRelationManager::class,
            DeploymentsRelationManager::class,
            ProfilesRelationManager::class,
            CalibrationsRelationManager::class,
            ConfigurationsRelationManager::class,
            HeartbeatsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDevices::route('/'),
            'create' => CreateDevice::route('/create'),
            'view' => ViewDevice::route('/{record}'),
            'edit' => EditDevice::route('/{record}/edit'),
        ];
    }
}
