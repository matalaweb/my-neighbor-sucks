<?php

namespace App\Policies;

use App\Models\NoiseEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Events are created only by devices. Reviewers annotate; owners set keep
 * flags and delete; viewers read and play audio.
 */
class NoiseEventPolicy extends AccountScopedPolicy
{
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function annotate(User $user, NoiseEvent $event): bool
    {
        return $user->canAnnotate($event->account_id);
    }

    public function keep(User $user, NoiseEvent $event): bool
    {
        return $user->canManage($event->account_id);
    }

    public function playRecording(User $user, NoiseEvent $event): bool
    {
        return $user->isMemberOf($event->account_id);
    }

    /** Viewers may play but not download originals. */
    public function downloadOriginal(User $user, NoiseEvent $event): bool
    {
        return $user->canExport($event->account_id);
    }
}
