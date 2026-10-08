<?php

namespace App\Services\Events;

use App\Enums\CompletenessState;
use App\Enums\DetectionState;
use App\Enums\MaintenanceJobKind;
use App\Enums\Metric;
use App\Enums\QualityFlag;
use App\Models\EventMeasurementSnapshot;
use App\Models\MaintenanceJob;
use App\Models\NoiseEvent;
use App\Services\Maintenance\MaintenanceQueue;
use App\Services\Measurements\QualityPolicy;
use App\Support\CanonicalJson;
use App\Support\Decibels;
use App\Support\Rfc3339;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Retains original measurement payloads for each event window: 10 s before
 * detection through 30 s after detection end, bounded by available data and
 * supplemented as late readings arrive (spec §9).
 *
 * A snapshot is frozen once the event is finalized and the window is
 * complete, or after the settle period as partial. Readings arriving after a
 * partial freeze produce a new version; frozen versions never change.
 */
class SnapshotEventMeasurements
{
    public function __construct(private readonly MaintenanceQueue $maintenance) {}

    public function processDirty(int $limit = 100): int
    {
        return $this->maintenance->work(
            [MaintenanceJobKind::EventSnapshot],
            function (MaintenanceJob $job): void {
                $event = NoiseEvent::query()->find($job->subject_id);

                if ($event !== null) {
                    $this->snapshot($event);
                }
            },
            $limit,
        );
    }

    public function snapshot(NoiseEvent $event, ?CarbonImmutable $now = null, bool $forceFreeze = false): EventMeasurementSnapshot
    {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($event, $now, $forceFreeze): EventMeasurementSnapshot {
            $event = NoiseEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $latest = $event->snapshots()->reorder('version', 'desc')->first();

            [$windowStart, $windowEnd] = $this->window($event);
            $collected = $this->collect($event, $windowStart, $windowEnd, $now);

            if ($latest !== null && $latest->isFrozen()) {
                if ($latest->completeness === CompletenessState::Complete || $collected['captured'] <= $latest->captured_intervals) {
                    $this->applyToEvent($event, $latest, $collected);

                    return $latest;
                }

                // Late readings after a partial freeze: new version, old one untouched.
                $latest = null;
            }

            $finalized = $event->detection_state === DetectionState::Finalized;
            $complete = $finalized && $collected['expected'] > 0 && $collected['captured'] >= $collected['expected'];
            $settled = $finalized && $event->ended_at->addHours((int) config('noise.events.snapshot_settle_hours'))->lessThan($now);
            $freeze = $complete || $settled || ($finalized && $forceFreeze);

            $completeness = match (true) {
                $complete => CompletenessState::Complete,
                ! $freeze => CompletenessState::Pending,
                $collected['captured'] === 0 => CompletenessState::Unavailable,
                default => CompletenessState::Partial,
            };

            $attributes = [
                'account_id' => $event->account_id,
                'status' => $freeze ? EventMeasurementSnapshot::STATUS_FROZEN : EventMeasurementSnapshot::STATUS_COLLECTING,
                'completeness' => $completeness,
                'window_start' => $windowStart,
                'window_end' => $windowEnd,
                'detection_start' => $event->started_at,
                'detection_end' => $event->ended_at,
                'expected_intervals' => $collected['expected'],
                'captured_intervals' => $collected['captured'],
                'missing_ranges' => $collected['missing_ranges'],
                'rows' => $collected['rows_json'],
                'content_hash' => $collected['content_hash'],
                'policy_version' => QualityPolicy::VERSION,
                'limitations' => $this->limitations($completeness, $collected, $freeze && ! $complete && $forceFreeze),
                'frozen_at' => $freeze ? $now : null,
            ];

            if ($latest === null) {
                $snapshot = $event->snapshots()->create([
                    ...$attributes,
                    'version' => ($event->snapshots()->max('version') ?? 0) + 1,
                ]);
            } else {
                $latest->fill($attributes)->save();
                $snapshot = $latest;
            }

            $this->applyToEvent($event, $snapshot, $collected);

            return $snapshot;
        });
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable|null}
     */
    public function window(NoiseEvent $event): array
    {
        $start = $event->started_at->subSeconds((int) config('noise.events.snapshot_pre_seconds'))->startOfSecond();
        $end = $event->ended_at?->addSeconds((int) config('noise.events.snapshot_post_seconds'));

        if ($end !== null && (int) $end->format('u') !== 0) {
            $end = $end->startOfSecond()->addSecond();
        }

        return [$start, $end];
    }

    /**
     * @return array{rows_json: string, content_hash: string, expected: int, captured: int, missing_ranges: list<array{start: string, end: string}>, summary: array<string, mixed>}
     */
    private function collect(NoiseEvent $event, CarbonImmutable $windowStart, ?CarbonImmutable $windowEnd, CarbonImmutable $now): array
    {
        $device = $event->device;

        // Open events are bounded by the latest available capture, never padded.
        $boundEnd = $windowEnd ?? ($device->latest_capture_at?->addSecond() ?? $windowStart);

        if ($boundEnd->lessThan($windowStart)) {
            $boundEnd = $windowStart;
        }

        $rows = DB::table('measurements as m')
            ->join('measurement_streams as s', 's.id', '=', 'm.stream_id')
            ->join('device_deployments as d', 'd.id', '=', 's.device_deployment_id')
            ->join('measurement_profiles as p', 'p.id', '=', 's.measurement_profile_id')
            ->leftJoin('device_calibrations as c', 'c.id', '=', 's.device_calibration_id')
            ->join('measurement_batches as b', 'b.id', '=', 'm.batch_id')
            ->where('m.account_id', $event->account_id)
            ->where('m.device_id', $event->device_id)
            ->where('m.channel', $event->channel)
            ->where('m.captured_at', '>=', Rfc3339::toDatabase($windowStart))
            ->where('m.captured_at', '<', Rfc3339::toDatabase($boundEnd))
            ->orderBy('m.captured_at')
            ->orderBy('m.boot_id')
            ->get([
                'm.boot_id', 'm.sequence', 'm.channel', 'm.captured_at', 'm.duration_ms', 'm.configuration_revision',
                'm.laeq_db', 'm.lafmax_db', 'm.lceq_db', 'm.lcpeak_db', 'm.low_frequency_leq_db', 'm.rms_dbfs',
                'm.quality_flags', 'm.null_reasons', 'm.bands', 'm.row_hash', 'm.received_at',
                'd.uuid as deployment_id', 'p.uuid as profile_id', 'c.uuid as calibration_id', 's.calibration_state',
                'b.uuid as batch_id',
            ]);

        $payloads = [];
        $seconds = [];

        foreach ($rows as $row) {
            $capturedAt = CarbonImmutable::parse($row->captured_at, 'UTC');
            $seconds[$capturedAt->getTimestamp()] = true;
            $payloads[] = [
                'boot_id' => $row->boot_id,
                'sequence' => (int) $row->sequence,
                'channel' => $row->channel,
                'captured_at' => Rfc3339::format($capturedAt),
                'duration_ms' => (int) $row->duration_ms,
                'deployment_id' => $row->deployment_id,
                'profile_id' => $row->profile_id,
                'calibration_id' => $row->calibration_id,
                'calibration_state' => $row->calibration_state,
                'configuration_revision' => (int) $row->configuration_revision,
                'laeq_db' => $this->float($row->laeq_db),
                'lafmax_db' => $this->float($row->lafmax_db),
                'lceq_db' => $this->float($row->lceq_db),
                'lcpeak_db' => $this->float($row->lcpeak_db),
                'low_frequency_leq_db' => $this->float($row->low_frequency_leq_db),
                'rms_dbfs' => $this->float($row->rms_dbfs),
                'quality_flags' => QualityFlag::valuesFromMask((int) $row->quality_flags),
                'null_reasons' => $row->null_reasons ? json_decode($row->null_reasons, true) : (object) [],
                'bands' => $row->bands ? json_decode($row->bands, true) : [],
                'row_sha256' => bin2hex($row->row_hash),
                'batch_id' => $row->batch_id,
                'received_at' => Rfc3339::format(CarbonImmutable::parse($row->received_at, 'UTC')),
            ];
        }

        $expectedEnd = $windowEnd ?? $boundEnd;
        $expected = max(0, $expectedEnd->getTimestamp() - $windowStart->getTimestamp());
        $missing = [];
        $gapStart = null;

        for ($second = $windowStart->getTimestamp(); $second < $expectedEnd->getTimestamp(); $second++) {
            if (! isset($seconds[$second])) {
                $gapStart ??= $second;
            } elseif ($gapStart !== null) {
                $missing[] = ['start' => Rfc3339::format(CarbonImmutable::createFromTimestampUTC($gapStart)), 'end' => Rfc3339::format(CarbonImmutable::createFromTimestampUTC($second))];
                $gapStart = null;
            }
        }

        if ($gapStart !== null) {
            $missing[] = ['start' => Rfc3339::format(CarbonImmutable::createFromTimestampUTC($gapStart)), 'end' => Rfc3339::format($expectedEnd)];
        }

        $rowsJson = json_encode($payloads, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return [
            'rows_json' => $rowsJson,
            'content_hash' => CanonicalJson::hash($payloads),
            'expected' => $expected,
            'captured' => count(array_filter(array_keys($seconds), fn (int $second): bool => $second < $expectedEnd->getTimestamp())),
            'missing_ranges' => array_slice($missing, 0, 200),
            'summary' => $this->serverSummary($event, $payloads),
        ];
    }

    /**
     * Server-derived summary over the detection window only, shown separately
     * from the agent summary.
     *
     * @param  list<array<string, mixed>>  $payloads
     * @return array<string, mixed>
     */
    private function serverSummary(NoiseEvent $event, array $payloads): array
    {
        $start = $event->started_at->getTimestamp();
        $end = $event->ended_at?->getTimestamp();
        $energy = [];
        $maxima = [];
        $validMs = [];
        $intervals = 0;
        $flagged = 0;

        foreach ($payloads as $payload) {
            $second = CarbonImmutable::parse($payload['captured_at'])->getTimestamp();

            if ($second < $start || ($end !== null && $second >= $end)) {
                continue;
            }

            $intervals++;
            $mask = QualityFlag::toMask($payload['quality_flags']);
            $flagged += $mask !== 0 ? 1 : 0;

            foreach (Metric::cases() as $metric) {
                $value = $payload[$metric->value];

                if ($value === null || QualityPolicy::excludes($metric, $mask)) {
                    continue;
                }

                if ($metric->isEnergy()) {
                    $energy[$metric->value] = ($energy[$metric->value] ?? 0.0) + Decibels::energy($value, $payload['duration_ms']);
                } else {
                    $maxima[$metric->value] = max($maxima[$metric->value] ?? -INF, $value);
                }

                $validMs[$metric->value] = ($validMs[$metric->value] ?? 0) + $payload['duration_ms'];
            }
        }

        $metrics = [];

        foreach (Metric::cases() as $metric) {
            $metrics[$metric->value] = $metric->isEnergy()
                ? Decibels::leq($energy[$metric->value] ?? null, $validMs[$metric->value] ?? 0)
                : ($maxima[$metric->value] ?? null);
            $metrics[$metric->value.'_valid_ms'] = $validMs[$metric->value] ?? 0;
        }

        return [
            'detection_expected_ms' => $end === null ? null : max(0, ($end - $start) * 1000),
            'detection_measured_ms' => $intervals * 1000,
            'flagged_intervals' => $flagged,
            'metrics' => $metrics,
            'policy_version' => QualityPolicy::VERSION,
        ];
    }

    /**
     * @param  array<string, mixed>  $collected
     */
    private function applyToEvent(NoiseEvent $event, EventMeasurementSnapshot $snapshot, array $collected): void
    {
        $event->forceFill([
            'completeness_state' => $snapshot->completeness,
            'server_summary' => $collected['summary'],
            'server_summary_at' => CarbonImmutable::now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $collected
     */
    private function limitations(CompletenessState $completeness, array $collected, bool $forced): ?string
    {
        return match ($completeness) {
            CompletenessState::Complete, CompletenessState::Pending => null,
            CompletenessState::Unavailable => 'No one-second readings were available for this event window'.($forced ? ' when the raw retention period ended.' : '.'),
            CompletenessState::Partial => sprintf(
                '%d of %d one-second intervals were available; %d gap(s) are listed as missing ranges%s.',
                $collected['captured'],
                $collected['expected'],
                count($collected['missing_ranges']),
                $forced ? ' (frozen before raw readings were deleted by retention)' : '',
            ),
        };
    }

    private function float(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
