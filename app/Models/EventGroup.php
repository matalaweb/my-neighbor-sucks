<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Reviewer-defined grouping of events (e.g. one visit). Stored separately
 * from source measurements.
 */
class EventGroup extends Model
{
    use BelongsToAccount, HasPublicUuid;

    protected $fillable = ['account_id', 'property_id', 'name', 'notes', 'created_by'];

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsToMany<NoiseEvent, $this> */
    public function noiseEvents(): BelongsToMany
    {
        return $this->belongsToMany(NoiseEvent::class, 'event_group_noise_event')->withPivot('added_by');
    }
}
