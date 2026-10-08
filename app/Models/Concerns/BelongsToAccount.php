<?php

namespace App\Models\Concerns;

use App\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every account-owned table carries account_id and is scoped through it.
 * Account IDs are always derived from the authenticated user or device,
 * never taken from request bodies.
 */
trait BelongsToAccount
{
    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeForAccount(Builder $query, Account|int $account): void
    {
        $query->where($this->qualifyColumn('account_id'), $account instanceof Account ? $account->getKey() : $account);
    }
}
