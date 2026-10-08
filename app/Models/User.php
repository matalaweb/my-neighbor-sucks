<?php

namespace App\Models;

use App\Enums\MembershipRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'current_account_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'current_account_id' => null,
        'disabled_at' => null,
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @var array<int, MembershipRole|null> */
    protected array $roleCache = [];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'disabled_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Account, $this> */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class)->withPivot('role')->withTimestamps();
    }

    /** @return BelongsTo<Account, $this> */
    public function currentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'current_account_id');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->disabled_at === null;
    }

    /** @return Collection<int, Account> */
    public function getTenants(Panel $panel): Collection
    {
        return $this->accounts;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Account && $this->roleIn($tenant) !== null;
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->currentAccount ?? $this->accounts()->first();
    }

    /**
     * Membership role for an account, or null when the user is not a member.
     */
    public function roleIn(Account|int|null $account): ?MembershipRole
    {
        if ($account === null) {
            return null;
        }

        $accountId = $account instanceof Account ? $account->getKey() : $account;

        if (! array_key_exists($accountId, $this->roleCache)) {
            $role = $this->accounts()->whereKey($accountId)->first()?->pivot?->role;
            $this->roleCache[$accountId] = $role ? MembershipRole::from($role) : null;
        }

        return $this->roleCache[$accountId];
    }

    public function forgetRoleCache(): void
    {
        $this->roleCache = [];
    }

    public function isMemberOf(Account|int|null $account): bool
    {
        return $this->roleIn($account) !== null;
    }

    public function canManage(Account|int|null $account): bool
    {
        return (bool) $this->roleIn($account)?->canManage();
    }

    public function canAnnotate(Account|int|null $account): bool
    {
        return (bool) $this->roleIn($account)?->canAnnotate();
    }

    public function canExport(Account|int|null $account): bool
    {
        return (bool) $this->roleIn($account)?->canExport();
    }
}
