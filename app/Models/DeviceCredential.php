<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bearer credential bound to exactly one device. Only the SHA-256 digest
 * of the secret is stored. It authenticates the device API guard only.
 */
class DeviceCredential extends Model implements Authenticatable
{
    use AuthenticatableTrait, BelongsToAccount, HasPublicUuid;

    public const ABILITY_MEASUREMENTS = 'measurements:write';

    public const ABILITY_EVENTS = 'events:write';

    public const ABILITY_CONFIGURATION = 'configuration:read';

    public const ABILITY_RECORDINGS = 'recordings:write';

    public const ABILITY_HEARTBEAT = 'heartbeat:write';

    public const ALL_ABILITIES = [
        self::ABILITY_MEASUREMENTS,
        self::ABILITY_EVENTS,
        self::ABILITY_CONFIGURATION,
        self::ABILITY_RECORDINGS,
        self::ABILITY_HEARTBEAT,
    ];

    protected $fillable = [
        'account_id', 'device_id', 'name', 'token_prefix', 'token_hash', 'abilities',
        'issued_by', 'expires_at', 'revoked_at', 'revoked_by',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function hasAbility(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** Device credentials have no remember-me token. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public static function hashSecret(string $plainText): string
    {
        return hash('sha256', $plainText);
    }
}
