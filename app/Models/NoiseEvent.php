<?php

namespace App\Models;

use App\Enums\CompletenessState;
use App\Enums\DetectionState;
use App\Enums\QualityFlag;
use App\Enums\RecordingStatus;
use App\Enums\ReviewConfidence;
use App\Enums\ReviewStatus;
use App\Enums\SourceCertainty;
use App\Enums\SourceLabel;
use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Current projection of the latest agent-reported revision plus separate
 * server-maintained review, completeness, and recording states. The agent
 * payloads themselves live immutably in noise_event_revisions.
 */
class NoiseEvent extends Model
{
    use BelongsToAccount, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'detection_state' => DetectionState::class,
            'completeness_state' => CompletenessState::class,
            'recording_state' => RecordingStatus::class,
            'review_status' => ReviewStatus::class,
            'source_label' => SourceLabel::class,
            'source_certainty' => SourceCertainty::class,
            'review_confidence' => ReviewConfidence::class,
            'agent_summary' => 'array',
            'server_summary' => 'array',
            'recording_expected' => 'boolean',
            'keep' => 'boolean',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'recording_started_at' => 'immutable_datetime',
            'recording_ended_at' => 'immutable_datetime',
            'server_summary_at' => 'immutable_datetime',
            'marked_incomplete_at' => 'immutable_datetime',
            'keep_changed_at' => 'immutable_datetime',
            'first_received_at' => 'immutable_datetime',
            'last_revision_received_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<MeasurementStream, $this> */
    public function stream(): BelongsTo
    {
        return $this->belongsTo(MeasurementStream::class, 'stream_id');
    }

    /** @return HasMany<NoiseEventRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(NoiseEventRevision::class)->orderBy('revision');
    }

    /** @return HasMany<EventRecording, $this> */
    public function recordings(): HasMany
    {
        return $this->hasMany(EventRecording::class)->orderBy('segment_number');
    }

    /** @return HasMany<EventAnnotation, $this> */
    public function annotations(): HasMany
    {
        return $this->hasMany(EventAnnotation::class)->orderBy('id');
    }

    /** @return HasMany<EventMeasurementSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(EventMeasurementSnapshot::class)->orderBy('version');
    }

    /** @return HasOne<EventMeasurementSnapshot, $this> */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(EventMeasurementSnapshot::class)->ofMany('version', 'max');
    }

    /** @return BelongsToMany<EventGroup, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(EventGroup::class, 'event_group_noise_event')->withPivot('added_by', 'created_at');
    }

    public function durationMs(): ?int
    {
        if ($this->ended_at === null) {
            return null;
        }

        return (int) round(($this->ended_at->getTimestampMs() - $this->started_at->getTimestampMs()));
    }

    /**
     * A finalized event whose agent stopped observing it (data loss, microphone disconnect,
     * restart): ended_at is where observation stopped, not where the sound ended.
     */
    public function observationInterrupted(): bool
    {
        return ! $this->isOpen() && in_array(QualityFlag::IncompleteInterval, $this->qualityFlagList(), true);
    }

    /**
     * A finalized event the agent ended because it was still above threshold at the configured
     * maximum event duration: the sound may have continued past ended_at.
     */
    public function endedAtMaxDuration(): bool
    {
        return ! $this->isOpen() && in_array(QualityFlag::MaxDurationReached, $this->qualityFlagList(), true);
    }

    /** The real duration may be longer than ended_at - started_at. */
    public function durationIsLowerBound(): bool
    {
        return $this->observationInterrupted() || $this->endedAtMaxDuration();
    }

    /** @return list<QualityFlag> */
    public function qualityFlagList(): array
    {
        return QualityFlag::fromMask((int) $this->quality_flags);
    }

    public function isOpen(): bool
    {
        return $this->detection_state === DetectionState::Open;
    }

    /**
     * "X dB above baseline" is a simple level difference, not source isolation.
     */
    public function levelAboveBaseline(): ?float
    {
        if ($this->trigger_value_db === null || $this->baseline_db === null) {
            return null;
        }

        return round($this->trigger_value_db - $this->baseline_db, 1);
    }
}
