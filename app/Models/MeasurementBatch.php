<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Batch receipt: the replay identity for a device batch UUID.
 */
class MeasurementBatch extends Model
{
    use BelongsToAccount;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'sent_at' => 'immutable_datetime',
            'accepted_from' => 'immutable_datetime',
            'accepted_to' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
