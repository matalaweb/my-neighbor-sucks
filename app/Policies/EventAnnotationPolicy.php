<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only annotations.
 */
class EventAnnotationPolicy extends AccountScopedPolicy
{
    public function create(User $user): bool
    {
        return $user->canAnnotate($this->tenantId());
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }
}
