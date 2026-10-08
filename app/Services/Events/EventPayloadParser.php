<?php

namespace App\Services\Events;

use App\Enums\DetectionState;
use App\Enums\Metric;
use App\Enums\QualityFlag;
use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\ErrorCode;
use App\Support\CanonicalJson;
use App\Support\Rfc3339;
use Carbon\CarbonImmutable;

/**
 * Transport validation for event revisions (schema_version 1). Each revision
 * must be a complete snapshot of the event.
 */
class EventPayloadParser
{
    private const FIELDS = [
        'schema_version', 'event_id', 'revision', 'sent_at', 'channel', 'deployment_id', 'profile_id', 'calibration_id',
        'configuration_revision', 'detection_state', 'started_at', 'ended_at', 'detection', 'summary', 'recording', 'quality_flags',
    ];

    private const DETECTION_FIELDS = ['rule_version', 'trigger_metric', 'trigger_kind', 'threshold_db', 'trigger_value_db', 'baseline_db', 'baseline_method'];

    private const SUMMARY_FIELDS = ['laeq_db', 'lafmax_db', 'lceq_db', 'lcpeak_db', 'low_frequency_leq_db', 'rms_dbfs', 'duration_ms'];

    private const RECORDING_FIELDS = ['expected', 'started_at', 'ended_at', 'expected_segments'];

    /** @var array<string, list<string>> */
    private array $errors = [];

    private bool $clockError = false;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parse(array $payload, CarbonImmutable $now): EventRevisionData
    {
        $this->errors = [];
        $this->clockError = false;

        if (($payload['schema_version'] ?? null) !== (int) config('noise.device_api.schema_version')) {
            throw new DeviceApiException(ErrorCode::UnsupportedSchemaVersion, 'schema_version must be '.config('noise.device_api.schema_version').'.');
        }

        $this->unknown($payload, self::FIELDS, '');

        $eventId = $this->uuid($payload['event_id'] ?? null, 'event_id');
        $revision = $this->integer($payload['revision'] ?? null, 'revision', 1);
        $channel = $payload['channel'] ?? null;

        if (! is_string($channel) || preg_match('/^[A-Za-z0-9._-]{1,32}$/', $channel) !== 1) {
            $this->error('channel', 'Must be 1–32 characters of A–Z, a–z, 0–9, ".", "_", "-".');
        }

        $deploymentId = $this->uuid($payload['deployment_id'] ?? null, 'deployment_id');
        $profileId = $this->uuid($payload['profile_id'] ?? null, 'profile_id');
        $calibrationId = ($payload['calibration_id'] ?? null) === null ? null : $this->uuid($payload['calibration_id'], 'calibration_id');
        $configurationRevision = $this->integer($payload['configuration_revision'] ?? null, 'configuration_revision', 1);

        $state = DetectionState::tryFrom((string) ($payload['detection_state'] ?? ''));

        if ($state === null) {
            $this->error('detection_state', 'Must be "open" or "finalized".');
        }

        $futureLimit = $now->addSeconds((int) config('noise.device_api.future_tolerance_seconds'));
        $startedAt = $this->timestamp($payload['started_at'] ?? null, 'started_at', $futureLimit, required: true);
        $endedAt = $this->timestamp($payload['ended_at'] ?? null, 'ended_at', $futureLimit, required: false);

        if ($state === DetectionState::Open && $endedAt !== null) {
            $this->error('ended_at', 'An open event must have a null end time.');
        }

        if ($state === DetectionState::Finalized && $endedAt === null) {
            $this->error('ended_at', 'A finalized event requires an end time.');
        }

        if ($startedAt !== null && $endedAt !== null && $endedAt->lessThan($startedAt)) {
            $this->error('ended_at', 'Must not precede started_at.');
        }

        $detection = $this->object($payload['detection'] ?? null, 'detection', self::DETECTION_FIELDS);
        $ruleVersion = $detection['rule_version'] ?? null;

        if (! is_string($ruleVersion) || $ruleVersion === '' || strlen($ruleVersion) > 64) {
            $this->error('detection.rule_version', 'Required string (max 64).');
        }

        $triggerMetric = Metric::tryFrom((string) ($detection['trigger_metric'] ?? ''));

        if ($triggerMetric === null) {
            $this->error('detection.trigger_metric', 'Must be a known metric name.');
        }

        $triggerKind = $detection['trigger_kind'] ?? null;

        if (! in_array($triggerKind, ['absolute', 'baseline_relative'], true)) {
            $this->error('detection.trigger_kind', 'Must be "absolute" or "baseline_relative".');
        }

        foreach (['threshold_db', 'trigger_value_db', 'baseline_db'] as $field) {
            $this->number($detection[$field] ?? null, 'detection.'.$field);
        }

        $baselineMethod = $detection['baseline_method'] ?? null;

        if ($baselineMethod !== null && (! is_string($baselineMethod) || strlen($baselineMethod) > 255)) {
            $this->error('detection.baseline_method', 'Must be a string (max 255) or null.');
        }

        $summary = $this->object($payload['summary'] ?? [], 'summary', self::SUMMARY_FIELDS);

        foreach (self::SUMMARY_FIELDS as $field) {
            $field === 'duration_ms'
                ? (($summary[$field] ?? null) === null ? null : $this->integer($summary[$field], 'summary.duration_ms', 0))
                : $this->number($summary[$field] ?? null, 'summary.'.$field);
        }

        $recording = $this->object($payload['recording'] ?? [], 'recording', self::RECORDING_FIELDS);
        $recordingExpected = $recording['expected'] ?? false;

        if (! is_bool($recordingExpected)) {
            $this->error('recording.expected', 'Must be a boolean.');
        }

        $recordingStartedAt = $this->timestamp($recording['started_at'] ?? null, 'recording.started_at', $futureLimit, required: false);
        $recordingEndedAt = $this->timestamp($recording['ended_at'] ?? null, 'recording.ended_at', $futureLimit, required: false);
        $expectedSegments = ($recording['expected_segments'] ?? null) === null ? null : $this->integer($recording['expected_segments'], 'recording.expected_segments', 1);

        $flags = $payload['quality_flags'] ?? [];
        $qualityFlags = [];

        if (! is_array($flags) || ! array_is_list($flags)) {
            $this->error('quality_flags', 'Must be an array.');
        } else {
            foreach ($flags as $index => $flag) {
                if (! is_string($flag) || QualityFlag::tryFrom($flag) === null) {
                    $this->error('quality_flags.'.$index, 'Unknown quality flag.');
                } else {
                    $qualityFlags[$flag] = $flag;
                }
            }
        }

        $qualityFlags = array_values($qualityFlags);
        sort($qualityFlags);

        if ($this->errors !== []) {
            throw new DeviceApiException(
                $this->clockError ? ErrorCode::ClockFutureTimestamp : ErrorCode::ValidationFailed,
                $this->clockError ? 'Event timestamps are implausibly far in the future; check the device clock.' : 'The event revision failed validation.',
                ['errors' => $this->errors],
            );
        }

        $normalizedSummary = [];

        foreach (self::SUMMARY_FIELDS as $field) {
            $value = $summary[$field] ?? null;
            $normalizedSummary[$field] = $value === null ? null : ($field === 'duration_ms' ? (int) $value : (float) $value);
        }

        $canonical = [
            'event_id' => $eventId,
            'revision' => $revision,
            'channel' => $channel,
            'deployment_id' => $deploymentId,
            'profile_id' => $profileId,
            'calibration_id' => $calibrationId,
            'configuration_revision' => $configurationRevision,
            'detection_state' => $state->value,
            'started_at' => Rfc3339::formatMicro($startedAt),
            'ended_at' => Rfc3339::formatMicro($endedAt),
            'detection' => [
                'rule_version' => $ruleVersion,
                'trigger_metric' => $triggerMetric->value,
                'trigger_kind' => $triggerKind,
                'threshold_db' => $this->floatOrNull($detection['threshold_db'] ?? null),
                'trigger_value_db' => $this->floatOrNull($detection['trigger_value_db'] ?? null),
                'baseline_db' => $this->floatOrNull($detection['baseline_db'] ?? null),
                'baseline_method' => $baselineMethod,
            ],
            'summary' => $normalizedSummary,
            'recording' => [
                'expected' => $recordingExpected,
                'started_at' => Rfc3339::formatMicro($recordingStartedAt),
                'ended_at' => Rfc3339::formatMicro($recordingEndedAt),
                'expected_segments' => $expectedSegments,
            ],
            'quality_flags' => $qualityFlags,
        ];

        return new EventRevisionData(
            eventUuid: $eventId,
            revision: $revision,
            channel: $channel,
            deploymentUuid: $deploymentId,
            profileUuid: $profileId,
            calibrationUuid: $calibrationId,
            configurationRevision: $configurationRevision,
            state: $state,
            startedAt: $startedAt,
            endedAt: $endedAt,
            recordingStartedAt: $recordingStartedAt,
            recordingEndedAt: $recordingEndedAt,
            recordingExpected: $recordingExpected,
            expectedSegments: $expectedSegments,
            canonical: $canonical,
            payloadHash: CanonicalJson::hash($canonical),
            qualityMask: QualityFlag::toMask($qualityFlags),
        );
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private function object(mixed $value, string $path, array $allowed): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->error($path, 'Must be an object.');

            return [];
        }

        $this->unknown($value, $allowed, $path.'.');

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $allowed
     */
    private function unknown(array $value, array $allowed, string $prefix): void
    {
        foreach (array_diff(array_keys($value), $allowed) as $unknown) {
            $this->error($prefix.$unknown, 'Unknown field.');
        }
    }

    private function timestamp(mixed $value, string $path, CarbonImmutable $futureLimit, bool $required): ?CarbonImmutable
    {
        if ($value === null) {
            if ($required) {
                $this->error($path, 'Required RFC 3339 timestamp.');
            }

            return null;
        }

        $parsed = Rfc3339::parse($value);

        if ($parsed === null) {
            $this->error($path, 'Must be an RFC 3339 timestamp.');

            return null;
        }

        if ($parsed->greaterThan($futureLimit)) {
            $this->error($path, 'Implausibly far in the future (clock problem).');
            $this->clockError = true;
        }

        return $parsed;
    }

    private function number(mixed $value, string $path): void
    {
        if ($value !== null && ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value))) {
            $this->error($path, 'Must be a finite number or null.');
        }
    }

    private function uuid(mixed $value, string $path): ?string
    {
        if (! is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower($value)) !== 1) {
            $this->error($path, 'Must be a UUID.');

            return null;
        }

        return strtolower($value);
    }

    private function integer(mixed $value, string $path, int $min): ?int
    {
        if (! is_int($value) || $value < $min) {
            $this->error($path, "Must be an integer >= {$min}.");

            return null;
        }

        return $value;
    }

    private function error(string $path, string $message): void
    {
        $this->errors[$path][] = $message;
    }
}
