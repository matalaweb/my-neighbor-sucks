<?php

namespace App\Services\Maintenance;

use RuntimeException;

/**
 * Thrown by a maintenance handler to release its record for a later pass
 * without counting a failure (e.g. an upload that is not visible yet).
 */
class RetryLater extends RuntimeException
{
    public function __construct(public readonly int $delaySeconds = 30, string $reason = '')
    {
        parent::__construct($reason);
    }
}
