<?php

namespace App\Services\Retention;

use App\Models\NoiseEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Explicit, owner-only destructive deletion of one event (separate from
 * archive and from automatic retention). Staged: recordings are purged
 * first (leaving audit tombstones with identity and hashes), then the event
 * rows. Kept events must have their keep flag removed first.
 */
class DeleteEvent
{
    public function __construct(private readonly ApplyRetention $retention) {}

    public function handle(NoiseEvent $event, User $owner, string $confirmation): void
    {
        if (! $owner->canManage($event->account_id)) {
            throw new AuthorizationException('Only owners can delete events.');
        }

        if ($event->keep) {
            throw ValidationException::withMessages(['event' => 'Remove the keep flag before deleting this event.']);
        }

        if (strtolower(trim($confirmation)) !== substr($event->uuid, 0, 8)) {
            throw ValidationException::withMessages(['confirmation' => 'Type the first 8 characters of the event ID to confirm.']);
        }

        foreach ($event->recordings()->whereNull('purged_at')->get() as $recording) {
            if (! $this->retention->purgeRecording($recording, 'owner_deleted_event')) {
                throw ValidationException::withMessages(['event' => 'A recording could not be purged; the deletion will not proceed. Check Operational status.']);
            }
        }

        if (! $this->retention->deleteEvent($event->fresh(), 'event.deleted_by_owner', $owner)) {
            throw ValidationException::withMessages(['event' => 'The event could not be deleted (it may have been kept concurrently).']);
        }
    }
}
