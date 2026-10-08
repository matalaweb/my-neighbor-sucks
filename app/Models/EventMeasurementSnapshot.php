<?php

namespace App\Models;

use App\Enums\CompletenessState;
use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Retained original measurement payloads for an event window. Collecting
 * snapshots are supplemented as late readings arrive; frozen snapshots never
 * change (later data creates a new version).
 */
class EventMeasurementSnapshot extends Model
{
    use BelongsToAccount, HasPublicUuid;

    public const STATUS_COLLECTING = 'collecting';

    public const STATUS_FROZEN = 'frozen';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'completeness' => CompletenessState::class,
            'missing_ranges' => 'array',
            'window_start' => 'immutable_datetime',
            'window_end' => 'immutable_datetime',
            'detection_start' => 'immutable_datetime',
            'detection_end' => 'immutable_datetime',
            'frozen_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $snapshot): void {
            if ($snapshot->getOriginal('status') === self::STATUS_FROZEN) {
                throw new LogicException('Frozen event snapshots are immutable.');
            }
        });
    }

    /** @return BelongsTo<NoiseEvent, $this> */
    public function noiseEvent(): BelongsTo
    {
        return $this->belongsTo(NoiseEvent::class);
    }

    public function isFrozen(): bool
    {
        return $this->status === self::STATUS_FROZEN;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function decodedRows(): array
    {
        return $this->rows ? json_decode($this->rows, true, flags: JSON_THROW_ON_ERROR) : [];
    }
}
