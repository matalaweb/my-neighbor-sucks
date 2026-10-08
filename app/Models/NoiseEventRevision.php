<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable agent event payload. Never edited by annotations or review.
 */
class NoiseEventRevision extends Model
{
    use BelongsToAccount, IsImmutable;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'applied_to_projection' => 'boolean',
            'received_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<NoiseEvent, $this> */
    public function noiseEvent(): BelongsTo
    {
        return $this->belongsTo(NoiseEvent::class);
    }
}
