<?php

namespace App\Models;

use App\Enums\ReviewConfidence;
use App\Enums\ReviewStatus;
use App\Enums\SourceCertainty;
use App\Enums\SourceLabel;
use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only reviewer annotation. Changes are new rows that reference the
 * superseded annotation; source payloads are never touched.
 */
class EventAnnotation extends Model
{
    use BelongsToAccount, HasPublicUuid, IsImmutable;

    public const KIND_REVIEW = 'review';

    public const KIND_NOTE = 'note';

    public const KIND_INCOMPLETE_MARKER = 'incomplete_marker';

    public const KIND_KEEP_CHANGE = 'keep_change';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'review_status' => ReviewStatus::class,
            'source_label' => SourceLabel::class,
            'source_certainty' => SourceCertainty::class,
            'confidence' => ReviewConfidence::class,
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<NoiseEvent, $this> */
    public function noiseEvent(): BelongsTo
    {
        return $this->belongsTo(NoiseEvent::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<EventAnnotation, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(EventAnnotation::class, 'supersedes_id');
    }
}
