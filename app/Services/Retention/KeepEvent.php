<?php

namespace App\Services\Retention;

use App\Models\EventAnnotation;
use App\Models\NoiseEvent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Sets or clears an event's keep flag (spec §16). The transition takes the
 * same event row lock as recording purges, so an active keep flag can never
 * race with object deletion. Keeping cannot recover audio that was already
 * purged or whose purge had already started.
 */
class KeepEvent
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return list<string> warnings for the person changing the flag
     *
     * @throws AuthorizationException
     */
    public function set(NoiseEvent $event, bool $keep, User $user, ?string $reason = null): array
    {
        if (! $user->canManage($event->account_id)) {
            throw new AuthorizationException('Only owners can change retention (keep) flags.');
        }

        return DB::transaction(function () use ($event, $keep, $user, $reason): array {
            $event = NoiseEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $warnings = [];

            if ($keep) {
                foreach ($event->recordings()->get() as $recording) {
                    if ($recording->purged_at !== null) {
                        $warnings[] = "Recording segment {$recording->segment_number} was already purged on ".$recording->purged_at->toDateString().'; keeping this event cannot recover it.';
                    } elseif ($recording->purge_started_at !== null) {
                        $warnings[] = "Recording segment {$recording->segment_number} was already being purged; keeping this event cannot recover it.";
                    }
                }
            }

            if ($event->keep === $keep) {
                return $warnings;
            }

            $now = CarbonImmutable::now();
            $event->forceFill(['keep' => $keep, 'keep_changed_at' => $now, 'keep_changed_by' => $user->id])->save();

            EventAnnotation::query()->create([
                'account_id' => $event->account_id,
                'noise_event_id' => $event->id,
                'author_id' => $user->id,
                'kind' => EventAnnotation::KIND_KEEP_CHANGE,
                'notes' => $reason,
                'metadata' => ['keep' => $keep],
                'created_at' => $now,
            ]);

            $this->audit->record($keep ? 'event.keep_set' : 'event.keep_cleared', $event, ['reason' => $reason], user: $user);

            return $warnings;
        });
    }
}
