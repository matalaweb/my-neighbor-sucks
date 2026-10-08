<?php

namespace App\Filament\Resources\Devices\RelationManagers;

use App\Models\Device;
use App\Models\DeviceCredential;
use App\Services\Devices\DeviceCredentialService;
use App\Support\LocalTime;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * Device credentials. Secrets are shown exactly once and stored only as a
 * SHA-256 digest.
 */
class CredentialsRelationManager extends RelationManager
{
    protected static string $relationship = 'credentials';

    protected static ?string $title = 'Credentials';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('token_prefix')->label('Prefix')->fontFamily('mono'),
                TextColumn::make('state')->badge()
                    ->state(fn (DeviceCredential $record): string => match (true) {
                        $record->revoked_at !== null => 'Revoked',
                        $record->expires_at !== null && $record->expires_at->isPast() => 'Expired',
                        $record->expires_at !== null => 'Rotating out',
                        default => 'Active',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Rotating out' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->label('Issued')->since(),
                TextColumn::make('expires_at')->label('Expires')->formatStateUsing(fn ($state): string => LocalTime::age($state))->placeholder('—'),
                TextColumn::make('last_used_at')->label('Last used')->since()->placeholder('never'),
            ])
            ->headerActions([
                Action::make('issue')
                    ->label('Issue credential')
                    ->icon('heroicon-o-key')
                    ->visible(fn (): bool => $this->canManage())
                    ->requiresConfirmation()
                    ->modalDescription('A new secret is generated and displayed once. Only its digest is stored.')
                    ->action(function (): void {
                        $this->issueAndShow(fn (Device $device) => app(DeviceCredentialService::class)->issue($device, auth()->user()));
                    }),
                Action::make('rotate')
                    ->label('Rotate')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (): bool => $this->canManage())
                    ->schema([
                        Select::make('window_hours')
                            ->label('Keep the old credential working for')
                            ->options([0 => 'Revoke immediately', 1 => '1 hour', 24 => '24 hours', 72 => '72 hours'])
                            ->default((int) config('noise.device_api.credential_rotation_hours'))
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $this->issueAndShow(fn (Device $device) => app(DeviceCredentialService::class)->rotate($device, auth()->user(), (int) $data['window_hours']));
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->color('danger')
                    ->icon('heroicon-o-no-symbol')
                    ->visible(fn (DeviceCredential $record): bool => $record->revoked_at === null && $this->canManage())
                    ->requiresConfirmation()
                    ->modalDescription('The credential stops working immediately. Device identity and history are unchanged.')
                    ->action(fn (DeviceCredential $record) => app(DeviceCredentialService::class)->revoke($record, auth()->user())),
            ]);
    }

    public function showTokenAction(): Action
    {
        return Action::make('showToken')
            ->modalHeading('Device credential — copy it now')
            ->modalDescription('This secret will not be shown again. Install it on the Pi agent as its bearer token.')
            ->modalContent(fn (array $arguments): HtmlString => new HtmlString(
                '<div class="space-y-3"><code class="block break-all rounded bg-gray-100 p-3 font-mono text-sm dark:bg-gray-800" data-testid="device-token">'
                .e($arguments['token'] ?? '')
                .'</code><p class="text-xs text-gray-500">API base URL: <code>'.e(url('/api/v1/device')).'</code></p></div>'
            ))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('I have stored it');
    }

    private function issueAndShow(\Closure $issue): void
    {
        /** @var Device $device */
        $device = $this->getOwnerRecord();

        try {
            $issued = $issue($device);
        } catch (RuntimeException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        $this->replaceMountedAction('showToken', ['token' => $issued['token']]);
    }

    private function canManage(): bool
    {
        return auth()->user()->canManage($this->getOwnerRecord()->account_id) && ! $this->getOwnerRecord()->isArchived();
    }
}
