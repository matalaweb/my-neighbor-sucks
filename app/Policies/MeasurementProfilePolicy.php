<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable revision records: create new revisions, never edit or delete.
 */
class MeasurementProfilePolicy extends AccountScopedPolicy
{
    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }
}
