<?php

namespace App\Models;

use App\Enums\QualityFlag;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw one-second reading. Ingestion uses bulk inserts and never hydrates a
 * model per reading; this model exists for detail views and tests.
 */
class Measurement extends Model
{
    use BelongsToAccount;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'captured_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'null_reasons' => 'array',
            'bands' => 'array',
            'laeq_db' => 'float',
            'lafmax_db' => 'float',
            'lceq_db' => 'float',
            'lcpeak_db' => 'float',
            'low_frequency_leq_db' => 'float',
            'rms_dbfs' => 'float',
        ];
    }

    /** @return BelongsTo<MeasurementStream, $this> */
    public function stream(): BelongsTo
    {
        return $this->belongsTo(MeasurementStream::class, 'stream_id');
    }

    /** @return list<string> */
    public function qualityFlagValues(): array
    {
        return QualityFlag::valuesFromMask((int) $this->quality_flags);
    }
}
