<?php

namespace App\Jobs;

use App\Enums\MaintenanceJobKind;
use App\Services\Maintenance\MaintenanceQueue;
use App\Services\Recordings\VerifyRecording;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Works pending recording verifications on the isolated "recordings" queue.
 */
class VerifyRecordings implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct()
    {
        $this->onQueue('recordings');
    }

    public function handle(VerifyRecording $verifier, MaintenanceQueue $maintenance): void
    {
        $deadline = microtime(true) + 600;

        do {
            $processed = $verifier->processDirty();
        } while ($processed > 0 && microtime(true) < $deadline);

        if ($maintenance->hasDue([MaintenanceJobKind::VerifyRecording])) {
            static::dispatch()->delay(15);
        }
    }
}
