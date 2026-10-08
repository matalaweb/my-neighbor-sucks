<?php

namespace App\Services\Ingestion;

use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;

final readonly class MeasurementBatchData
{
    /**
     * @param  list<MeasurementRecord>  $records
     */
    public function __construct(
        public int $schemaVersion,
        public string $batchUuid,
        public ?CarbonImmutable $sentAt,
        public array $records,
    ) {}

    /**
     * Semantic batch hash: schema version, batch id, and the canonical
     * records sorted by identity. sent_at is transport metadata and is
     * excluded so a retry with a new send time is still the same batch.
     */
    public function payloadHash(): string
    {
        $records = array_map(fn (MeasurementRecord $record): array => $record->canonical, $this->records);

        usort($records, fn (array $a, array $b): int => [$a['channel'], $a['boot_id'], $a['sequence']] <=> [$b['channel'], $b['boot_id'], $b['sequence']]);

        return CanonicalJson::hash([
            'schema_version' => $this->schemaVersion,
            'batch_id' => $this->batchUuid,
            'records' => $records,
        ]);
    }
}
