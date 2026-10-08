<?php

namespace App\Services\Accounts;

use App\Enums\MembershipRole;
use App\Models\Account;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Owner-only membership administration. No public signup exists; owners
 * create local users or send expiring invitations (passwords are never
 * sent in messages).
 */
class MembershipService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function invite(Account $account, User $owner, string $email, MembershipRole $role): Invitation
    {
        $this->authorize($account, $owner);
        $email = Str::lower(trim($email));

        if ($account->users()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'That person is already a member.']);
        }

        $token = Str::random(48);

        $invitation = DB::transaction(function () use ($account, $owner, $email, $role, $token): Invitation {
            $account->invitations()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => CarbonImmutable::now()]);

            return $account->invitations()->create([
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $owner->id,
                'expires_at' => CarbonImmutable::now()->addDays((int) config('noise.invitations.expiry_days')),
            ]);
        });

        Notification::route('mail', $email)->notify(new AccountInvitation($account, $invitation, $token));
        $this->audit->record('membership.invited', $invitation, ['email' => $email, 'role' => $role->value], user: $owner);

        return $invitation;
    }

    public function accept(string $token, string $name, string $password): User
    {
        return DB::transaction(function () use ($token, $name, $password): User {
            $invitation = Invitation::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();

            if ($invitation === null || ! $invitation->isUsable()) {
                throw ValidationException::withMessages(['token' => 'This invitation is invalid, expired, or already used.']);
            }

            $user = User::query()->where('email', $invitation->email)->first()
                ?? tap(new User(['name' => $name, 'email' => $invitation->email, 'password' => $password]), fn (User $new) => $new->forceFill(['email_verified_at' => CarbonImmutable::now()])->save());

            $invitation->account->users()->syncWithoutDetaching([$user->id => ['role' => $invitation->role->value]]);
            $invitation->forceFill(['accepted_at' => CarbonImmutable::now()])->save();
            $user->forceFill(['current_account_id' => $user->current_account_id ?? $invitation->account_id])->save();
            $this->audit->record('membership.invitation_accepted', $invitation, ['user_id' => $user->id], accountId: $invitation->account_id, user: $user);

            return $user;
        });
    }

    public function createLocalUser(Account $account, User $owner, string $name, string $email, string $password, MembershipRole $role): User
    {
        $this->authorize($account, $owner);

        return DB::transaction(function () use ($account, $owner, $name, $email, $password, $role): User {
            $user = User::query()->where('email', Str::lower($email))->first()
                ?? User::query()->create(['name' => $name, 'email' => Str::lower($email), 'password' => $password, 'current_account_id' => $account->id]);

            $account->users()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
            $this->audit->record('membership.local_user_added', $user, ['role' => $role->value], accountId: $account->id, user: $owner);

            return $user;
        });
    }

    public function changeRole(Account $account, User $owner, User $member, MembershipRole $role): void
    {
        $this->authorize($account, $owner);

        DB::transaction(function () use ($account, $owner, $member, $role): void {
            Account::query()->whereKey($account->id)->lockForUpdate()->first();
            $this->guardLastOwner($account, $member, $role === MembershipRole::Owner);
            $account->users()->updateExistingPivot($member->id, ['role' => $role->value]);
            $this->audit->record('membership.role_changed', $member, ['role' => $role->value], accountId: $account->id, user: $owner);
        });
    }

    public function remove(Account $account, User $owner, User $member): void
    {
        $this->authorize($account, $owner);

        DB::transaction(function () use ($account, $owner, $member): void {
            Account::query()->whereKey($account->id)->lockForUpdate()->first();
            $this->guardLastOwner($account, $member, false);
            $account->users()->detach($member->id);
            $this->audit->record('membership.removed', $member, [], accountId: $account->id, user: $owner);
        });
    }

    private function guardLastOwner(Account $account, User $member, bool $remainsOwner): void
    {
        $isOwner = $account->users()->whereKey($member->id)->wherePivot('role', MembershipRole::Owner->value)->exists();
        $owners = $account->users()->wherePivot('role', MembershipRole::Owner->value)->count();

        if ($isOwner && ! $remainsOwner && $owners <= 1) {
            throw ValidationException::withMessages(['member' => 'An account must keep at least one owner.']);
        }
    }

    private function authorize(Account $account, User $owner): void
    {
        $owner->forgetRoleCache();

        if (! $owner->canManage($account)) {
            throw new AuthorizationException('Only owners can administer memberships.');
        }
    }
}
