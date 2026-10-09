<?php

namespace App\Services\Ingestion;

use Carbon\CarbonImmutable;

/**
 * One validated, canonicalized measurement record.
 */
final readonly class MeasurementRecord
{
    /**
     * @param  array<string, float|null>  $metrics  keyed by Metric value
     * @param  array<string, string>  $nullReasons
     * @param  list<string>  $qualityFlags
     * @param  list<array{center_hz: float, level_db: float|null, weighting: string}>  $bands
     * @param  array<string, mixed>  $canonical
     */
    public function __construct(
        public int $index,
        public string $bootId,
        public int $sequence,
        public string $channel,
        public CarbonImmutable $capturedAt,
        public int $durationMs,
        public string $profileUuid,
        public ?string $calibrationUuid,
        public ?int $configurationRevision,
        public array $metrics,
        public array $nullReasons,
        public array $qualityFlags,
        public int $qualityMask,
        public array $bands,
        public array $canonical,
        public string $rowHash,
    ) {}

    public function identityKey(): string
    {
        return $this->channel.'|'.$this->bootId.'|'.$this->sequence;
    }

    public function endsAt(): CarbonImmutable
    {
        return $this->capturedAt->addMilliseconds($this->durationMs);
    }
}
