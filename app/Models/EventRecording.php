<?php

namespace App\Models;

use App\Enums\RecordingStatus;
use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One recording segment for an event. The declaration is immutable; the
 * verified original lives at a server-owned final key the device cannot
 * write to.
 */
class EventRecording extends Model
{
    use BelongsToAccount, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RecordingStatus::class,
            'media_info' => 'array',
            'capture_started_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'purge_started_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
            'declared_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<NoiseEvent, $this> */
    public function noiseEvent(): BelongsTo
    {
        return $this->belongsTo(NoiseEvent::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return HasMany<RecordingUploadAttempt, $this> */
    public function uploadAttempts(): HasMany
    {
        return $this->hasMany(RecordingUploadAttempt::class)->orderBy('id');
    }

    public function isPlayable(): bool
    {
        return $this->status === RecordingStatus::Verified && $this->final_key !== null && $this->purged_at === null;
    }

    public function fileExtension(): string
    {
        return $this->mime_type === 'audio/flac' ? 'flac' : 'wav';
    }
}
