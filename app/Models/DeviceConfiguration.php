<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Complete, immutable device configuration document with integer revision
 * and SHA-256 content hash (spec §14). Never a merge patch.
 */
class DeviceConfiguration extends Model
{
    use BelongsToAccount, HasPublicUuid, IsImmutable;

    public $timestamps = false;

    protected $fillable = [
        'account_id', 'device_id', 'revision', 'document', 'content_hash', 'rollback_of_revision',
        'notes', 'created_by', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'document' => 'array',
            'issued_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<DeviceConfigAcknowledgment, $this> */
    public function acknowledgments(): HasMany
    {
        return $this->hasMany(DeviceConfigAcknowledgment::class);
    }
}
