<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Immutable revision records: once written they may not be updated. Changes
 * are expressed by creating a new revision.
 */
trait IsImmutable
{
    public static function bootIsImmutable(): void
    {
        static::updating(function ($model): void {
            throw new LogicException(class_basename($model).' records are immutable; create a new revision instead.');
        });
    }
}
