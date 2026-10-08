<?php

namespace App\Services\Ingestion;

use App\Enums\Metric;
use App\Enums\QualityFlag;
use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\ErrorCode;
use App\Support\CanonicalJson;
use App\Support\Rfc3339;
use Carbon\CarbonImmutable;

/**
 * Transport validation for measurement batches (schema_version 1).
 *
 * Hand-written rather than wildcard validator rules so a 300-record batch
 * validates in a few milliseconds. Unknown fields and non-finite numbers are
 * rejected; every error is reported with its JSON path.
 */
class MeasurementBatchParser
{
    private const ENVELOPE_FIELDS = ['schema_version', 'batch_id', 'sent_at', 'records'];

    private const RECORD_FIELDS = [
        'boot_id', 'sequence', 'channel', 'captured_at', 'duration_ms', 'deployment_id', 'profile_id',
        'calibration_id', 'configuration_revision', 'laeq_db', 'lafmax_db', 'lceq_db', 'lcpeak_db',
        'low_frequency_leq_db', 'rms_dbfs', 'quality_flags', 'bands', 'null_reasons',
    ];

    private const BAND_FIELDS = ['center_hz', 'level_db', 'weighting'];

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** @var array<string, list<string>> */
    private array $errors = [];

    private bool $clockError = false;

    private bool $windowError = false;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parse(array $payload, CarbonImmutable $now, ?\Closure $backfillAllowed = null): MeasurementBatchData
    {
        $this->errors = [];
        $this->clockError = false;
        $this->windowError = false;

        if (($payload['schema_version'] ?? null) !== (int) config('noise.device_api.schema_version')) {
            throw new DeviceApiException(ErrorCode::UnsupportedSchemaVersion, 'schema_version must be '.config('noise.device_api.schema_version').'.', [
                'supported_schema_versions' => [(int) config('noise.device_api.schema_version')],
            ]);
        }

        foreach (array_diff(array_keys($payload), self::ENVELOPE_FIELDS) as $unknown) {
            $this->error((string) $unknown, 'Unknown field.');
        }

        $batchUuid = $this->uuid($payload['batch_id'] ?? null, 'batch_id');
        $sentAt = null;

        if (array_key_exists('sent_at', $payload) && $payload['sent_at'] !== null) {
            $sentAt = Rfc3339::parse($payload['sent_at']);

            if ($sentAt === null) {
                $this->error('sent_at', 'Must be an RFC 3339 timestamp.');
            }
        }

        $rawRecords = $payload['records'] ?? null;
        $maxRecords = (int) config('noise.device_api.max_records_per_batch');
        $records = [];

        if (! is_array($rawRecords) || ! array_is_list($rawRecords) || $rawRecords === []) {
            $this->error('records', 'Must be a non-empty array.');
        } elseif (count($rawRecords) > $maxRecords) {
            throw new DeviceApiException(ErrorCode::PayloadTooLarge, 'A batch may contain at most '.$maxRecords.' records.', [
                'max_records' => $maxRecords,
            ]);
        } else {
            $futureLimit = $now->addSeconds((int) config('noise.device_api.future_tolerance_seconds'));
            $backfillLimit = $now->subDays((int) config('noise.device_api.backfill_days'));

            foreach ($rawRecords as $index => $raw) {
                $record = $this->parseRecord($index, $raw, $futureLimit, $backfillLimit, $backfillAllowed);

                if ($record !== null) {
                    $records[] = $record;
                }
            }
        }

        if ($this->errors !== []) {
            $code = match (true) {
                $this->clockError => ErrorCode::ClockFutureTimestamp,
                $this->windowError => ErrorCode::OutsideBackfillWindow,
                default => ErrorCode::ValidationFailed,
            };

            throw new DeviceApiException($code, match ($code) {
                ErrorCode::ClockFutureTimestamp => 'One or more records end more than '.config('noise.device_api.future_tolerance_seconds').' seconds in the future; check the device clock.',
                ErrorCode::OutsideBackfillWindow => 'One or more records are older than the permitted backfill window; an owner must enable an import window.',
                default => 'The batch failed validation; nothing was stored.',
            }, ['errors' => $this->errors]);
        }

        return new MeasurementBatchData(1, (string) $batchUuid, $sentAt, $records);
    }

    private function parseRecord(int $index, mixed $raw, CarbonImmutable $futureLimit, CarbonImmutable $backfillLimit, ?\Closure $backfillAllowed): ?MeasurementRecord
    {
        $path = 'records.'.$index;

        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            $this->error($path, 'Must be an object.');

            return null;
        }

        $errorCount = count($this->errors);

        foreach (array_diff(array_keys($raw), self::RECORD_FIELDS) as $unknown) {
            $this->error($path.'.'.$unknown, 'Unknown field.');
        }

        $bootId = $this->uuid($raw['boot_id'] ?? null, $path.'.boot_id');
        $sequence = $this->integer($raw['sequence'] ?? null, $path.'.sequence', 0);
        $channel = $raw['channel'] ?? null;

        if (! is_string($channel) || preg_match('/^[A-Za-z0-9._-]{1,32}$/', $channel) !== 1) {
            $this->error($path.'.channel', 'Must be 1–32 characters of A–Z, a–z, 0–9, ".", "_", "-".');
            $channel = null;
        }

        $capturedAt = Rfc3339::parse($raw['captured_at'] ?? null);
        $durationMs = $this->integer($raw['duration_ms'] ?? null, $path.'.duration_ms', 1);
        $expectedDuration = (int) config('noise.measurements.interval_ms');

        if ($durationMs !== null && $durationMs !== $expectedDuration) {
            $this->error($path.'.duration_ms', 'MVP measurements must cover exactly '.$expectedDuration.' ms.');
        }

        if ($capturedAt === null) {
            $this->error($path.'.captured_at', 'Must be an RFC 3339 timestamp.');
        } elseif ((int) $capturedAt->format('u') !== 0) {
            $this->error($path.'.captured_at', 'Must be aligned to a UTC second boundary.');
        } elseif ($durationMs !== null) {
            if ($capturedAt->addMilliseconds($durationMs)->greaterThan($futureLimit)) {
                $this->error($path.'.captured_at', 'Interval ends more than the permitted tolerance in the future (clock problem).');
                $this->clockError = true;
            } elseif ($capturedAt->lessThan($backfillLimit) && ! ($backfillAllowed !== null && $backfillAllowed($capturedAt))) {
                $this->error($path.'.captured_at', 'Older than the permitted backfill window.');
                $this->windowError = true;
            }
        }

        $deploymentUuid = $this->uuid($raw['deployment_id'] ?? null, $path.'.deployment_id');
        $profileUuid = $this->uuid($raw['profile_id'] ?? null, $path.'.profile_id');
        $calibrationUuid = null;

        if (array_key_exists('calibration_id', $raw) && $raw['calibration_id'] !== null) {
            $calibrationUuid = $this->uuid($raw['calibration_id'], $path.'.calibration_id');
        }

        $configurationRevision = $this->integer($raw['configuration_revision'] ?? null, $path.'.configuration_revision', 1);

        $metrics = [];

        foreach (Metric::cases() as $metric) {
            $value = $raw[$metric->value] ?? null;
            $metrics[$metric->value] = null;

            if ($value === null) {
                continue;
            }

            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                $this->error($path.'.'.$metric->value, 'Must be a finite number or null.');

                continue;
            }

            $value = (float) $value;
            [$min, $max] = $metric === Metric::RmsDbfs
                ? [(float) config('noise.measurements.dbfs_min'), 3.02]
                : [(float) config('noise.measurements.metric_min_db'), (float) config('noise.measurements.metric_max_db')];

            if ($value < $min || $value > $max) {
                $this->error($path.'.'.$metric->value, "Must be between {$min} and {$max}.");

                continue;
            }

            $metrics[$metric->value] = $value;
        }

        $qualityFlags = [];
        $rawFlags = $raw['quality_flags'] ?? [];

        if (! is_array($rawFlags) || ! array_is_list($rawFlags)) {
            $this->error($path.'.quality_flags', 'Must be an array of flag names.');
        } else {
            foreach ($rawFlags as $flagIndex => $flag) {
                if (! is_string($flag) || QualityFlag::tryFrom($flag) === null) {
                    $this->error($path.'.quality_flags.'.$flagIndex, 'Unknown quality flag.');

                    continue;
                }

                $qualityFlags[$flag] = $flag;
            }
        }

        $qualityFlags = array_values($qualityFlags);
        sort($qualityFlags);

        $nullReasons = [];
        $rawReasons = $raw['null_reasons'] ?? [];

        if (! is_array($rawReasons) || ($rawReasons !== [] && array_is_list($rawReasons))) {
            $this->error($path.'.null_reasons', 'Must be an object mapping metric name to reason.');
        } else {
            foreach ($rawReasons as $metricName => $reason) {
                if (Metric::tryFrom((string) $metricName) === null) {
                    $this->error($path.'.null_reasons.'.$metricName, 'Unknown metric.');
                } elseif (! is_string($reason) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reason) !== 1) {
                    $this->error($path.'.null_reasons.'.$metricName, 'Reason must be a lowercase identifier (e.g. "unsupported", "unreliable").');
                } elseif ($metrics[$metricName] !== null) {
                    $this->error($path.'.null_reasons.'.$metricName, 'A reason may only be given for a null metric.');
                } else {
                    $nullReasons[(string) $metricName] = $reason;
                }
            }
        }

        ksort($nullReasons);

        if (array_filter($metrics, fn (?float $value): bool => $value !== null) === [] && $qualityFlags === []) {
            $this->error($path, 'At least one metric must be supplied, or quality flags must explain why none are available.');
        }

        $bands = $this->bands($raw['bands'] ?? [], $path.'.bands');

        if (count($this->errors) > $errorCount) {
            return null;
        }

        $canonical = [
            'boot_id' => $bootId,
            'sequence' => $sequence,
            'channel' => $channel,
            'captured_at' => Rfc3339::formatMicro($capturedAt),
            'duration_ms' => $durationMs,
            'deployment_id' => $deploymentUuid,
            'profile_id' => $profileUuid,
            'calibration_id' => $calibrationUuid,
            'configuration_revision' => $configurationRevision,
            ...$metrics,
            'quality_flags' => $qualityFlags,
            'null_reasons' => $nullReasons,
            'bands' => $bands,
        ];

        return new MeasurementRecord(
            index: $index,
            bootId: (string) $bootId,
            sequence: (int) $sequence,
            channel: (string) $channel,
            capturedAt: $capturedAt,
            durationMs: (int) $durationMs,
            deploymentUuid: (string) $deploymentUuid,
            profileUuid: (string) $profileUuid,
            calibrationUuid: $calibrationUuid,
            configurationRevision: (int) $configurationRevision,
            metrics: $metrics,
            nullReasons: $nullReasons,
            qualityFlags: $qualityFlags,
            qualityMask: QualityFlag::toMask($qualityFlags),
            bands: $bands,
            canonical: $canonical,
            rowHash: CanonicalJson::binaryHash($canonical),
        );
    }

    /**
     * @return list<array{center_hz: float, level_db: float|null, weighting: string}>
     */
    private function bands(mixed $raw, string $path): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $this->error($path, 'Must be an array.');

            return [];
        }

        $bands = [];

        foreach ($raw as $index => $band) {
            $bandPath = $path.'.'.$index;

            if (! is_array($band) || array_is_list($band)) {
                $this->error($bandPath, 'Must be an object.');

                continue;
            }

            foreach (array_diff(array_keys($band), self::BAND_FIELDS) as $unknown) {
                $this->error($bandPath.'.'.$unknown, 'Unknown field.');
            }

            $center = $band['center_hz'] ?? null;
            $level = $band['level_db'] ?? null;
            $weighting = $band['weighting'] ?? null;

            if ((! is_int($center) && ! is_float($center)) || ! is_finite((float) $center) || $center <= 0) {
                $this->error($bandPath.'.center_hz', 'Must be a positive number.');

                continue;
            }

            if ($level !== null && ((! is_int($level) && ! is_float($level)) || ! is_finite((float) $level))) {
                $this->error($bandPath.'.level_db', 'Must be a finite number or null.');

                continue;
            }

            if (! in_array($weighting, ['Z', 'A', 'C'], true)) {
                $this->error($bandPath.'.weighting', 'Must be one of Z, A, C.');

                continue;
            }

            $key = (string) (float) $center;

            if (isset($bands[$key])) {
                $this->error($bandPath.'.center_hz', 'Duplicate band centre.');

                continue;
            }

            $bands[$key] = ['center_hz' => (float) $center, 'level_db' => $level === null ? null : (float) $level, 'weighting' => $weighting];
        }

        usort($bands, fn (array $a, array $b): int => $a['center_hz'] <=> $b['center_hz']);

        return array_values($bands);
    }

    private function uuid(mixed $value, string $path): ?string
    {
        if (! is_string($value) || preg_match(self::UUID_PATTERN, strtolower($value)) !== 1) {
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
