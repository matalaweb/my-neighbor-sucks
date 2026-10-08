<?php

namespace App\Services\Events;

use App\Enums\DetectionState;
use App\Enums\ReviewConfidence;
use App\Enums\ReviewStatus;
use App\Enums\SourceCertainty;
use App\Enums\SourceLabel;
use App\Models\EventAnnotation;
use App\Models\EventGroup;
use App\Models\NoiseEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Append-only reviewer annotations (spec §9). Every change is a new row
 * referencing the annotation it supersedes; the agent's source payloads are
 * never modified. The event's review fields are only a projection.
 */
class AnnotateEvent
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{review_status: ReviewStatus|string, source_label?: SourceLabel|string|null, source_certainty?: SourceCertainty|string|null, confidence?: ReviewConfidence|string|null, notes?: string|null}  $data
     */
    public function review(NoiseEvent $event, User $user, array $data): EventAnnotation
    {
        $this->authorize($event, $user);

        $status = $data['review_status'] instanceof ReviewStatus ? $data['review_status'] : ReviewStatus::from($data['review_status']);
        $label = $this->enum(SourceLabel::class, $data['source_label'] ?? null);
        $certainty = $this->enum(SourceCertainty::class, $data['source_certainty'] ?? null);
        $confidence = $this->enum(ReviewConfidence::class, $data['confidence'] ?? null);

        if ($label !== null && $certainty === null) {
            throw ValidationException::withMessages(['source_certainty' => 'Say whether the source was observed or only suspected.']);
        }

        return DB::transaction(function () use ($event, $user, $status, $label, $certainty, $confidence, $data): EventAnnotation {
            $event = NoiseEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $annotation = EventAnnotation::query()->create([
                'account_id' => $event->account_id,
                'noise_event_id' => $event->id,
                'author_id' => $user->id,
                'kind' => EventAnnotation::KIND_REVIEW,
                'review_status' => $status,
                'source_label' => $label,
                'source_certainty' => $certainty,
                'confidence' => $confidence,
                'notes' => $this->clean($data['notes'] ?? null),
                'supersedes_id' => $event->latest_review_annotation_id,
                'created_at' => CarbonImmutable::now(),
            ]);

            $event->forceFill([
                'review_status' => $status,
                'source_label' => $label,
                'source_certainty' => $certainty,
                'review_confidence' => $confidence,
                'latest_review_annotation_id' => $annotation->id,
            ])->save();

            $this->audit->record('event.reviewed', $event, [
                'annotation_uuid' => $annotation->uuid,
                'review_status' => $status->value,
                'source_label' => $label?->value,
                'supersedes_id' => $annotation->supersedes_id,
            ], user: $user);

            return $annotation;
        });
    }

    public function note(NoiseEvent $event, User $user, string $notes): EventAnnotation
    {
        $this->authorize($event, $user);

        $annotation = EventAnnotation::query()->create([
            'account_id' => $event->account_id,
            'noise_event_id' => $event->id,
            'author_id' => $user->id,
            'kind' => EventAnnotation::KIND_NOTE,
            'notes' => $this->clean($notes),
            'created_at' => CarbonImmutable::now(),
        ]);

        $this->audit->record('event.note_added', $event, ['annotation_uuid' => $annotation->uuid], user: $user);

        return $annotation;
    }

    /**
     * Mark an open event (e.g. after an agent crash) as incomplete without
     * inventing an end time. A later valid final revision may still close it.
     */
    public function markIncomplete(NoiseEvent $event, User $user, ?string $notes = null): EventAnnotation
    {
        $this->authorize($event, $user);

        if ($event->detection_state !== DetectionState::Open) {
            throw ValidationException::withMessages(['event' => 'Only open events can be marked incomplete.']);
        }

        return DB::transaction(function () use ($event, $user, $notes): EventAnnotation {
            $annotation = EventAnnotation::query()->create([
                'account_id' => $event->account_id,
                'noise_event_id' => $event->id,
                'author_id' => $user->id,
                'kind' => EventAnnotation::KIND_INCOMPLETE_MARKER,
                'notes' => $this->clean($notes) ?? 'Marked incomplete by reviewer; the device never finalized this event.',
                'created_at' => CarbonImmutable::now(),
            ]);

            NoiseEvent::query()->whereKey($event->id)->update(['marked_incomplete_at' => CarbonImmutable::now()]);
            $this->audit->record('event.marked_incomplete', $event, ['annotation_uuid' => $annotation->uuid], user: $user);

            return $annotation;
        });
    }

    public function group(NoiseEvent $event, User $user, string $name): EventGroup
    {
        $this->authorize($event, $user);

        return DB::transaction(function () use ($event, $user, $name): EventGroup {
            $group = EventGroup::query()->firstOrCreate(
                ['account_id' => $event->account_id, 'property_id' => $event->property_id, 'name' => trim($name)],
                ['created_by' => $user->id],
            );

            $group->noiseEvents()->syncWithoutDetaching([$event->id => ['added_by' => $user->id, 'created_at' => CarbonImmutable::now()]]);
            $this->audit->record('event.grouped', $event, ['group_uuid' => $group->uuid, 'group' => $group->name], user: $user);

            return $group;
        });
    }

    private function authorize(NoiseEvent $event, User $user): void
    {
        if (! $user->canAnnotate($event->account_id)) {
            throw new AuthorizationException('Only owners and reviewers can annotate events.');
        }
    }

    private function clean(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        $notes = trim(strip_tags($notes));

        return $notes === '' ? null : mb_substr($notes, 0, 10000);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private function enum(string $enum, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof $enum ? $value : $enum::from($value);
    }
}
