<?php

namespace App\Models;

use App\Enums\ClockSyncState;
use App\Enums\MicrophoneState;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceHeartbeat extends Model
{
    use BelongsToAccount;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'microphone_state' => MicrophoneState::class,
            'clock_sync_state' => ClockSyncState::class,
            'oldest_pending_capture_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** Large offsets are surfaced explicitly (spec §14). */
    public function hasClockProblem(): bool
    {
        return $this->clock_sync_state !== ClockSyncState::Synchronized
            || ($this->clock_offset_ms !== null && abs($this->clock_offset_ms) > 1000);
    }
}
