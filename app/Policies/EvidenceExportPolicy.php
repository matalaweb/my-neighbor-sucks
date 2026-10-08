<?php

namespace App\Policies;

use App\Models\EvidenceExport;
use App\Models\User;

/**
 * Owners and reviewers create and download exports; viewers can see the
 * list but never download originals; only owners delete.
 */
class EvidenceExportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EvidenceExport $export): bool
    {
        return $user->isMemberOf($export->account_id);
    }

    /**
     * Account-specific checks happen in RequestEvidenceExport; here the user
     * must hold an export-capable role somewhere.
     */
    public function create(User $user): bool
    {
        return $user->accounts()->wherePivotIn('role', ['owner', 'reviewer'])->exists();
    }

    public function download(User $user, EvidenceExport $export): bool
    {
        return $user->canExport($export->account_id);
    }

    public function retry(User $user, EvidenceExport $export): bool
    {
        return $user->canExport($export->account_id);
    }

    public function delete(User $user, EvidenceExport $export): bool
    {
        return $user->canManage($export->account_id);
    }
}
