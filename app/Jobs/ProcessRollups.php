<?php

namespace App\Jobs;

use App\Enums\MaintenanceJobKind;
use App\Services\Maintenance\MaintenanceQueue;
use App\Services\Measurements\RebuildRollups;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Works the durable dirty-bucket records. Dispatch is only a wake-up hint;
 * the scheduled reconciler dispatches it again if anything is left behind.
 */
class ProcessRollups implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue('rollups');
    }

    public static function dispatchFor(int $deviceId): void
    {
        static::dispatch()->afterCommit();
    }

    public function handle(RebuildRollups $rebuilder, MaintenanceQueue $maintenance): void
    {
        $deadline = microtime(true) + 50;

        do {
            $processed = $rebuilder->processDirty();
        } while ($processed > 0 && microtime(true) < $deadline);

        if ($maintenance->hasDue([MaintenanceJobKind::RollupMinute, MaintenanceJobKind::RollupHour])) {
            static::dispatch()->delay(5);
        }
    }
}
