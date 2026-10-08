<?php

namespace App\Models;

use App\Enums\UploadAttemptState;
use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordingUploadAttempt extends Model
{
    use BelongsToAccount, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'state' => UploadAttemptState::class,
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'verification_started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'staging_deleted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<EventRecording, $this> */
    public function recording(): BelongsTo
    {
        return $this->belongsTo(EventRecording::class, 'event_recording_id');
    }
}
