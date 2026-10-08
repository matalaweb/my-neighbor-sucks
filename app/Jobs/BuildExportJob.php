<?php

namespace App\Jobs;

use App\Models\EvidenceExport;
use App\Services\Exports\BuildEvidenceExport;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds one export on the isolated "exports" queue so large reports never
 * delay new data display. Safe under duplicate delivery: a ready export is a
 * no-op and a partial run rebuilds to the same object key.
 */
class BuildExportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public int $exportId)
    {
        $this->onQueue('exports');
    }

    public function uniqueId(): string
    {
        return (string) $this->exportId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(BuildEvidenceExport $builder): void
    {
        $export = EvidenceExport::query()->find($this->exportId);

        if ($export !== null) {
            $builder->build($export);
        }
    }
}
