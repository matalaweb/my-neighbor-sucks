<?php

namespace App\Jobs;

use App\Enums\MaintenanceJobKind;
use App\Services\Events\SnapshotEventMeasurements;
use App\Services\Maintenance\MaintenanceQueue;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessEventSnapshots implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue('rollups');
    }

    public function handle(SnapshotEventMeasurements $snapshots, MaintenanceQueue $maintenance): void
    {
        $deadline = microtime(true) + 50;

        do {
            $processed = $snapshots->processDirty();
        } while ($processed > 0 && microtime(true) < $deadline);

        if ($maintenance->hasDue([MaintenanceJobKind::EventSnapshot])) {
            static::dispatch()->delay(5);
        }
    }
}
