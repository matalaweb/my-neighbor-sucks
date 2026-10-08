<?php

namespace App\Services\Events;

use App\Enums\DetectionState;
use App\Enums\RecordingStatus;
use App\Models\NoiseEvent;
use Carbon\CarbonImmutable;

/**
 * Derives the event-level recording state from its segments. An event stays
 * visible and reviewable whatever its recording state.
 */
class RecordingStateProjector
{
    public function compute(NoiseEvent $event, ?CarbonImmutable $now = null): ?RecordingStatus
    {
        $now ??= CarbonImmutable::now();
        $statuses = $event->recordings()->pluck('status')->map(fn ($status): RecordingStatus => $status instanceof RecordingStatus ? $status : RecordingStatus::from($status));

        if ($statuses->isEmpty()) {
            if (! $event->recording_expected) {
                return null;
            }

            $missingAfter = (int) config('noise.events.recording_missing_after_hours');
            $reference = $event->ended_at ?? $event->last_revision_received_at;

            if ($event->detection_state === DetectionState::Finalized && $reference !== null && $reference->addHours($missingAfter)->lessThan($now)) {
                return RecordingStatus::Missing;
            }

            return RecordingStatus::Pending;
        }

        $all = fn (RecordingStatus $status): bool => $statuses->every(fn (RecordingStatus $value): bool => $value === $status);
        $any = fn (RecordingStatus $status): bool => $statuses->contains(fn (RecordingStatus $value): bool => $value === $status);

        return match (true) {
            $all(RecordingStatus::Purged) => RecordingStatus::Purged,
            $any(RecordingStatus::Failed) => RecordingStatus::Failed,
            $all(RecordingStatus::Verified) && ($event->expected_segment_count === null || $statuses->count() >= $event->expected_segment_count) => RecordingStatus::Verified,
            $any(RecordingStatus::Verifying) || $any(RecordingStatus::Uploaded) => RecordingStatus::Verifying,
            $any(RecordingStatus::Purged) && ! $any(RecordingStatus::Pending) => RecordingStatus::Purged,
            default => RecordingStatus::Pending,
        };
    }

    public function refresh(NoiseEvent $event): void
    {
        $state = $this->compute($event);

        if ($event->recording_state !== $state) {
            $event->forceFill(['recording_state' => $state])->save();
        }
    }
}
