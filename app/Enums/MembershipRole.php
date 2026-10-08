<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MembershipRole: string implements HasLabel
{
    case Owner = 'owner';
    case Reviewer = 'reviewer';
    case Viewer = 'viewer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Reviewer => 'Reviewer',
            self::Viewer => 'Viewer',
        };
    }

    /** Owners manage users, devices, credentials, settings, retention, deletion, and exports. */
    public function canManage(): bool
    {
        return $this === self::Owner;
    }

    /** Owners and reviewers annotate events. */
    public function canAnnotate(): bool
    {
        return $this === self::Owner || $this === self::Reviewer;
    }

    /** Owners and reviewers create exports and download originals. */
    public function canExport(): bool
    {
        return $this === self::Owner || $this === self::Reviewer;
    }
}
