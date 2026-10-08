<?php

namespace App\Policies;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared ownership rules: every record is reachable only by members of its
 * account. Owners manage; reviewers annotate/export; viewers read.
 */
abstract class AccountScopedPolicy
{
    protected function tenantId(): ?int
    {
        return Filament::getTenant()?->getKey();
    }

    public function viewAny(User $user): bool
    {
        return $user->isMemberOf($this->tenantId());
    }

    public function view(User $user, Model $record): bool
    {
        return $user->isMemberOf($record->getAttribute('account_id'));
    }

    public function create(User $user): bool
    {
        return $user->canManage($this->tenantId());
    }

    public function update(User $user, Model $record): bool
    {
        return $user->canManage($record->getAttribute('account_id'));
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->canManage($record->getAttribute('account_id'));
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
