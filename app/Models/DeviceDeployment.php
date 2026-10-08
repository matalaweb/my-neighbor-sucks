<?php

namespace App\Models;

use App\Enums\LocationType;
use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Immutable microphone placement revision.
 */
class DeviceDeployment extends Model
{
    use BelongsToAccount, HasPublicUuid, IsImmutable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'account_id', 'device_id', 'revision', 'room', 'location_type', 'placement_description',
        'mounting_notes', 'height_m', 'orientation', 'effective_at', 'content_hash', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'location_type' => LocationType::class,
            'effective_at' => 'immutable_datetime',
            'height_m' => 'decimal:2',
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

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function label(): string
    {
        return 'Placement r'.$this->revision.' — '.($this->room ?: 'unspecified room').' ('.$this->location_type->getLabel().')';
    }
}
