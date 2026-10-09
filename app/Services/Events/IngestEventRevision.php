<?php

namespace App\Services\Events;

use App\Enums\CompletenessState;
use App\Enums\DetectionState;
use App\Enums\MaintenanceJobKind;
use App\Enums\Metric;
use App\Enums\RecordingStatus;
use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\ErrorCode;
use App\Jobs\ProcessEventSnapshots;
use App\Models\Device;
use App\Models\NoiseEvent;
use App\Models\NoiseEventRevision;
use App\Services\Ingestion\ProvenanceResolver;
use App\Services\Maintenance\MaintenanceQueue;
use App\Support\Rfc3339;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Stores an immutable agent event revision and advances the projection
 * (spec §9):
 *  - identical repeated revision: success, no change
 *  - changed content under an existing revision: 409
 *  - older unseen revision: stored for history, never rolls the projection back
 *  - newer revision: replaces the projection
 *  - finalized events are terminal
 */
class IngestEventRevision
{
    public function __construct(
        private readonly EventPayloadParser $parser,
        private readonly ProvenanceResolver $provenance,
        private readonly MaintenanceQueue $maintenance,
        private readonly RecordingStateProjector $recordingState,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(Device $device, array $payload, CarbonImmutable $receivedAt, ?string $requestId = null): array
    {
        $data = $this->parser->parse($payload, $receivedAt);
        $streamId = $this->resolveStream($device, $data);

        for ($attempt = 1; ; $attempt++) {
            try {
                $result = DB::transaction(fn (): array => $this->apply($device, $data, $streamId, $receivedAt, $requestId), attempts: 3);
                break;
            } catch (UniqueConstraintViolationException $exception) {
                // Concurrent first delivery of the same event; retry against the committed row.
                if ($attempt >= 3) {
                    throw $exception;
                }
            }
        }

        ProcessEventSnapshots::dispatch()->afterCommit();

        return $result;
    }

    private function resolveStream(Device $device, EventRevisionData $data): int
    {
        $this->provenance->load($device, [$data->profileUuid], array_values(array_filter([$data->calibrationUuid])), [$data->configurationRevision]);

        $errors = [];
        $unknown = false;
        $tuple = $this->provenance->check('', $data->channel, $data->startedAt, $data->profileUuid, $data->calibrationUuid, $data->configurationRevision, $errors, $unknown);

        if ($tuple !== null) {
            [, $profile] = $tuple;

            if (! $profile->calibration_state->allowsAbsoluteLevels()) {
                foreach ($data->canonical['summary'] as $field => $value) {
                    if ($value !== null && $field !== 'duration_ms' && $field !== Metric::RmsDbfs->value) {
                        $errors['summary.'.$field][] = 'Absolute SPL values must be null for an uncalibrated profile.';
                    }
                }

                if ($data->canonical['detection']['trigger_metric'] !== Metric::RmsDbfs->value) {
                    $errors['detection.trigger_metric'][] = 'An uncalibrated profile can only trigger on rms_dbfs.';
                }
            }
        }

        if ($errors !== []) {
            $errors = array_combine(array_map(fn (string $key): string => ltrim($key, '.'), array_keys($errors)), $errors);

            throw new DeviceApiException(
                $unknown ? ErrorCode::UnknownProvenance : ErrorCode::ValidationFailed,
                $unknown ? 'Event provenance references are not registered for this device; register the measurement chain (POST /provenance) and resubmit.' : 'Event provenance is inconsistent.',
                ['errors' => $errors],
            );
        }

        [$deployment, $profile, $calibration] = $tuple;

        // The placement in effect when the event started (null when none was recorded yet).
        return $this->provenance->streamIds($device, [0 => [$data->channel, $deployment?->id, $profile->id, $calibration?->id, $profile->calibration_state]])[0];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function apply(Device $device, EventRevisionData $data, int $streamId, CarbonImmutable $receivedAt, ?string $requestId): array
    {
        $event = NoiseEvent::query()
            ->where('device_id', $device->id)
            ->where('uuid', $data->eventUuid)
            ->lockForUpdate()
            ->first();

        if ($event === null) {
            $event = new NoiseEvent([
                'uuid' => $data->eventUuid,
                'account_id' => $device->account_id,
                'property_id' => $device->property_id,
                'device_id' => $device->id,
                'review_status' => 'unreviewed',
                'completeness_state' => CompletenessState::Pending,
                'first_received_at' => $receivedAt,
            ]);
            $this->project($event, $data, $streamId, $receivedAt);
            $event->recording_state = $data->recordingExpected ? RecordingStatus::Pending : null;
            $event->save();

            $this->storeRevision($event, $data, true, $receivedAt, $requestId);
            $this->markSnapshot($event);

            return $this->response($event, $data, 201, 'stored', true);
        }

        $existing = NoiseEventRevision::query()->where('noise_event_id', $event->id)->where('revision', $data->revision)->first();

        if ($existing !== null) {
            if (! hash_equals($existing->payload_hash, $data->payloadHash)) {
                throw new DeviceApiException(ErrorCode::EventRevisionConflict, 'This event revision was already received with different content.', [
                    'event_id' => $event->uuid,
                    'revision' => $data->revision,
                ]);
            }

            return $this->response($event, $data, 200, 'duplicate', $existing->applied_to_projection);
        }

        if ($data->channel !== $event->channel) {
            throw new DeviceApiException(ErrorCode::EventRevisionConflict, 'An event revision may not change the event channel.');
        }

        if ($data->revision < $event->current_revision) {
            // Older unseen revision: keep for history, never roll back.
            $this->storeRevision($event, $data, false, $receivedAt, $requestId);

            return $this->response($event, $data, 200, 'stored_superseded', false);
        }

        if ($event->detection_state === DetectionState::Finalized) {
            throw new DeviceApiException(ErrorCode::EventTerminal, 'This event is finalized; finalized events are terminal.', [
                'event_id' => $event->uuid,
                'current_revision' => $event->current_revision,
            ]);
        }

        $this->project($event, $data, $streamId, $receivedAt);
        $event->save();
        $this->storeRevision($event, $data, true, $receivedAt, $requestId);
        $this->recordingState->refresh($event);
        $this->markSnapshot($event);

        return $this->response($event, $data, 201, 'stored', true);
    }

    private function project(NoiseEvent $event, EventRevisionData $data, int $streamId, CarbonImmutable $receivedAt): void
    {
        $detection = $data->canonical['detection'];

        $event->fill([
            'channel' => $data->channel,
            'stream_id' => $streamId,
            'configuration_revision' => $data->configurationRevision,
            'current_revision' => $data->revision,
            'detection_state' => $data->state,
            'started_at' => $data->startedAt,
            'ended_at' => $data->endedAt,
            'recording_started_at' => $data->recordingStartedAt,
            'recording_ended_at' => $data->recordingEndedAt,
            'detection_rule_version' => $detection['rule_version'],
            'trigger_metric' => $detection['trigger_metric'],
            'trigger_kind' => $detection['trigger_kind'],
            'trigger_threshold_db' => $detection['threshold_db'],
            'trigger_value_db' => $detection['trigger_value_db'],
            'baseline_db' => $detection['baseline_db'],
            'baseline_method' => $detection['baseline_method'],
            'agent_summary' => $data->canonical['summary'],
            'quality_flags' => $data->qualityMask,
            'recording_expected' => $data->recordingExpected,
            'expected_segment_count' => $data->expectedSegments,
            'last_revision_received_at' => $receivedAt,
        ]);
    }

    private function storeRevision(NoiseEvent $event, EventRevisionData $data, bool $applied, CarbonImmutable $receivedAt, ?string $requestId): void
    {
        NoiseEventRevision::query()->create([
            'account_id' => $event->account_id,
            'noise_event_id' => $event->id,
            'revision' => $data->revision,
            'payload' => $data->canonical,
            'payload_hash' => $data->payloadHash,
            'applied_to_projection' => $applied,
            'received_at' => $receivedAt,
            'request_id' => $requestId,
        ]);
    }

    private function markSnapshot(NoiseEvent $event): void
    {
        $this->maintenance->mark(MaintenanceJobKind::EventSnapshot, (string) $event->id, $event->account_id, 'noise_event', $event->id);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function response(NoiseEvent $event, EventRevisionData $data, int $status, string $outcome, bool $applied): array
    {
        return [
            'status' => $status,
            'body' => [
                'event_id' => $event->uuid,
                'revision' => $data->revision,
                'outcome' => $outcome,
                'applied_to_projection' => $applied,
                'current_revision' => $event->current_revision,
                'detection_state' => $event->detection_state->value,
                'completeness_state' => $event->completeness_state->value,
                'recording_state' => $event->recording_state?->value,
                'first_received_at' => Rfc3339::format($event->first_received_at),
            ],
        ];
    }
}
