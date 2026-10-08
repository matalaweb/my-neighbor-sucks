<?php

namespace App\Models;

use App\Enums\MembershipRole;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

/**
 * Household/account boundary. All owned data is scoped to an account.
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory, HasPublicUuid;

    protected $fillable = ['name', 'settings'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<Property, $this> */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<NoiseEvent, $this> */
    public function noiseEvents(): HasMany
    {
        return $this->hasMany(NoiseEvent::class);
    }

    /** @return HasMany<EvidenceExport, $this> */
    public function evidenceExports(): HasMany
    {
        return $this->hasMany(EvidenceExport::class);
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function roleOf(User $user): ?MembershipRole
    {
        $membership = $this->users()->whereKey($user->getKey())->first();

        return $membership ? MembershipRole::from($membership->pivot->role) : null;
    }

    /**
     * Retention setting with configured default fallback.
     */
    public function retention(string $key): ?int
    {
        $value = Arr::get($this->settings ?? [], 'retention.'.$key, config('noise.retention.'.$key));

        return $value === null ? null : (int) $value;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->settings ?? [], $key, $default);
    }

    public function recordingsEnabled(): bool
    {
        return (bool) $this->setting('recording.enabled', true);
    }
}
