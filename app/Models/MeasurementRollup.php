<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeasurementRollup extends Model
{
    use BelongsToAccount;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'bucket_start' => 'immutable_datetime',
            'rebuilt_at' => 'immutable_datetime',
            'quality_counts' => 'array',
            'configuration_revisions' => 'array',
            'bands' => 'array',
        ];
    }

    /** @return BelongsTo<MeasurementStream, $this> */
    public function stream(): BelongsTo
    {
        return $this->belongsTo(MeasurementStream::class, 'stream_id');
    }
}
