<?php

namespace App\Services\Events;

use App\Enums\DetectionState;
use Carbon\CarbonImmutable;

final readonly class EventRevisionData
{
    /**
     * @param  array<string, mixed>  $canonical
     */
    public function __construct(
        public string $eventUuid,
        public int $revision,
        public string $channel,
        public string $deploymentUuid,
        public string $profileUuid,
        public ?string $calibrationUuid,
        public int $configurationRevision,
        public DetectionState $state,
        public CarbonImmutable $startedAt,
        public ?CarbonImmutable $endedAt,
        public ?CarbonImmutable $recordingStartedAt,
        public ?CarbonImmutable $recordingEndedAt,
        public bool $recordingExpected,
        public ?int $expectedSegments,
        public array $canonical,
        public string $payloadHash,
        public int $qualityMask,
    ) {}
}
