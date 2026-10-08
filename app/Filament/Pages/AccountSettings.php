<?php

namespace App\Filament\Pages;

use App\Enums\MembershipRole;
use App\Enums\RecordingStatus;
use App\Models\Account;
use App\Services\Accounts\MembershipService;
use App\Services\Audit\AuditLogger;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Members/roles, retention, recording policy, and storage totals. Owners
 * administer; reviewers and viewers can read.
 */
class AccountSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Account';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Account settings';

    protected string $view = 'filament.pages.account-settings';

    public function account(): Account
    {
        /** @var Account */
        return Filament::getTenant();
    }

    private function isOwner(): bool
    {
        return auth()->user()->canManage($this->account());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Invite member')
                ->icon('heroicon-o-envelope')
                ->visible($this->isOwner())
                ->modalDescription('Sends an expiring link (valid '.config('noise.invitations.expiry_days').' days). The invitee chooses their own password; passwords are never emailed.')
                ->schema([
                    TextInput::make('email')->email()->required(),
                    Select::make('role')->options(MembershipRole::class)->default(MembershipRole::Viewer->value)->required(),
                ])
                ->action(function (array $data): void {
                    $this->guard(fn () => app(MembershipService::class)->invite($this->account(), auth()->user(), $data['email'], ($data['role'] instanceof MembershipRole ? $data['role'] : MembershipRole::from($data['role']))));
                }),
            Action::make('addLocal')
                ->label('Add local user')
                ->color('gray')
                ->visible($this->isOwner())
                ->schema([
                    TextInput::make('name')->required(),
                    TextInput::make('email')->email()->required(),
                    TextInput::make('password')->password()->revealable()->required()->minLength(12)->helperText('Share it with them in person; they can change it under Profile.'),
                    Select::make('role')->options(MembershipRole::class)->default(MembershipRole::Viewer->value)->required(),
                ])
                ->action(function (array $data): void {
                    $this->guard(fn () => app(MembershipService::class)->createLocalUser($this->account(), auth()->user(), $data['name'], $data['email'], $data['password'], ($data['role'] instanceof MembershipRole ? $data['role'] : MembershipRole::from($data['role']))));
                }),
            Action::make('policies')
                ->label('Retention & recording policy')
                ->icon('heroicon-o-adjustments-horizontal')
                ->visible($this->isOwner())
                ->modalWidth('2xl')
                ->fillForm(fn (): array => [
                    'recording_enabled' => $this->account()->recordingsEnabled(),
                    ...collect(config('noise.retention'))->keys()->mapWithKeys(fn (string $key): array => [$key => $this->account()->retention($key)])->all(),
                ])
                ->schema([
                    Section::make('Recording policy')->schema([
                        Toggle::make('recording_enabled')->label('Accept event recordings')->helperText('Audio is limited to event clips. There is no continuous recording, transcription, or identity attribution.'),
                    ]),
                    Section::make('Retention (days)')->description('Kept events are preserved regardless. Keeping cannot recover audio that was already purged.')->schema([
                        TextInput::make('raw_measurements_days')->label('One-second measurements')->numeric()->minValue(1)->required(),
                        TextInput::make('minute_rollups_days')->label('Minute rollups')->numeric()->minValue(1)->required(),
                        TextInput::make('hour_rollups_days')->label('Hour rollups (blank = keep)')->numeric()->minValue(1),
                        TextInput::make('events_days')->label('Event metadata, annotations, snapshots')->numeric()->minValue(1)->required(),
                        TextInput::make('recordings_days')->label('Event recordings')->numeric()->minValue(1)->required(),
                        TextInput::make('heartbeats_days')->label('Detailed heartbeats')->numeric()->minValue(1)->required(),
                        TextInput::make('health_summaries_days')->label('Device health summaries')->numeric()->minValue(1)->required(),
                        TextInput::make('exports_days')->label('Finished exports')->numeric()->minValue(1)->required(),
                        TextInput::make('staging_hours')->label('Abandoned uploads (hours)')->numeric()->minValue(1)->required(),
                        TextInput::make('replay_receipts_days')->label('Replay receipts')->numeric()->minValue((int) config('noise.device_api.backfill_days') + 1)->required()
                            ->helperText('Must exceed the '.config('noise.device_api.backfill_days').'-day replay window.'),
                    ])->columns(2),
                ])
                ->action(function (array $data): void {
                    $account = $this->account();
                    $settings = $account->settings ?? [];
                    $settings['recording']['enabled'] = (bool) $data['recording_enabled'];

                    foreach (array_keys(config('noise.retention')) as $key) {
                        $settings['retention'][$key] = $data[$key] === null || $data[$key] === '' ? null : (int) $data[$key];
                    }

                    $before = $account->settings;
                    $account->forceFill(['settings' => $settings])->save();
                    app(AuditLogger::class)->record('account.settings_updated', $account, ['before' => $before, 'after' => $settings]);
                    Notification::make()->title('Settings saved.')->success()->send();
                }),
        ];
    }

    public function changeRoleAction(): Action
    {
        return Action::make('changeRole')
            ->label('Change role')
            ->size('sm')
            ->link()
            ->visible($this->isOwner())
            ->schema([Select::make('role')->options(MembershipRole::class)->required()])
            ->fillForm(fn (array $arguments): array => ['role' => $this->account()->users()->whereKey($arguments['user'])->first()?->pivot->role])
            ->action(function (array $data, array $arguments): void {
                $member = $this->account()->users()->whereKey($arguments['user'])->firstOrFail();
                $this->guard(fn () => app(MembershipService::class)->changeRole($this->account(), auth()->user(), $member, ($data['role'] instanceof MembershipRole ? $data['role'] : MembershipRole::from($data['role']))));
            });
    }

    public function removeMemberAction(): Action
    {
        return Action::make('removeMember')
            ->label('Remove')
            ->size('sm')
            ->link()
            ->color('danger')
            ->visible($this->isOwner())
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                $member = $this->account()->users()->whereKey($arguments['user'])->firstOrFail();
                $this->guard(fn () => app(MembershipService::class)->remove($this->account(), auth()->user(), $member));
            });
    }

    public function revokeInvitationAction(): Action
    {
        return Action::make('revokeInvitation')
            ->label('Revoke')
            ->size('sm')
            ->link()
            ->color('danger')
            ->visible($this->isOwner())
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                $invitation = $this->account()->invitations()->whereKey($arguments['invitation'])->firstOrFail();
                $invitation->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
                app(AuditLogger::class)->record('membership.invitation_revoked', $invitation);
            });
    }

    private function guard(callable $operation): void
    {
        try {
            $operation();
            Notification::make()->title('Saved.')->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $account = $this->account();

        return [
            'account' => $account,
            'members' => $account->users()->orderBy('name')->get(),
            'invitations' => $account->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->latest()->get(),
            'retention' => collect(config('noise.retention'))->keys()->mapWithKeys(fn (string $key): array => [$key => $account->retention($key)])->all(),
            'recordingEnabled' => $account->recordingsEnabled(),
            'storage' => [
                'recordings_bytes' => (int) DB::table('event_recordings')->where('account_id', $account->id)->where('status', RecordingStatus::Verified->value)->sum('verified_byte_size'),
                'recordings_count' => DB::table('event_recordings')->where('account_id', $account->id)->where('status', RecordingStatus::Verified->value)->count(),
                'exports_bytes' => (int) DB::table('evidence_exports')->where('account_id', $account->id)->whereNull('object_deleted_at')->sum('byte_size'),
                'attachments_bytes' => (int) DB::table('attachments')->where('account_id', $account->id)->sum('byte_size'),
                'measurement_rows' => (int) DB::table('measurements')->where('account_id', $account->id)->count(),
                'rollup_rows' => (int) DB::table('measurement_rollups')->where('account_id', $account->id)->count(),
            ],
        ];
    }
}
