<?php

namespace App\Services\Measurements;

use App\Enums\MaintenanceJobKind;
use App\Enums\Metric;
use App\Enums\QualityFlag;
use App\Models\MaintenanceJob;
use App\Services\Maintenance\MaintenanceQueue;
use App\Support\Decibels;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds UTC-aligned minute buckets from authoritative raw rows and hour
 * buckets from minute buckets (spec §11).
 *
 * Equivalent-level metrics keep energy_sum and valid duration separately so
 * hours combine exactly from minutes; LAFmax/LCpeak combine with max().
 * Missing time contributes nothing. Intervals reported by more than one
 * boot/stream for a channel are ambiguous and excluded.
 */
class RebuildRollups
{
    public const MINUTE = 60;

    public const HOUR = 3600;

    private const ENERGY = ['laeq' => 'laeq_db', 'lceq' => 'lceq_db', 'lf' => 'low_frequency_leq_db', 'dbfs' => 'rms_dbfs'];

    private const MAXIMA = ['lafmax' => 'lafmax_db', 'lcpeak' => 'lcpeak_db'];

    public function __construct(private readonly MaintenanceQueue $maintenance) {}

    /**
     * Work all due dirty buckets: minutes first, then the hours they dirtied.
     */
    public function processDirty(int $limit = 500): int
    {
        $processed = $this->maintenance->work(
            [MaintenanceJobKind::RollupMinute],
            fn (MaintenanceJob $job) => $this->rebuildMinute((int) $job->subject_id, (string) $job->payload['channel'], $job->bucket_start),
            $limit,
        );

        return $processed + $this->maintenance->work(
            [MaintenanceJobKind::RollupHour],
            fn (MaintenanceJob $job) => $this->rebuildHour((int) $job->subject_id, (string) $job->payload['channel'], $job->bucket_start),
            $limit,
        );
    }

    public function rebuildMinute(int $deviceId, string $channel, CarbonImmutable $minute): void
    {
        $minute = $minute->utc()->startOfMinute();
        $device = DB::table('devices')->where('id', $deviceId)->first(['id', 'account_id']);

        if ($device === null) {
            return;
        }

        $rows = DB::table('measurements')
            ->where('account_id', $device->account_id)
            ->where('device_id', $deviceId)
            ->where('channel', $channel)
            ->where('captured_at', '>=', $minute->format('Y-m-d H:i:s'))
            ->where('captured_at', '<', $minute->addMinute()->format('Y-m-d H:i:s'))
            ->get(['stream_id', 'boot_id', 'captured_at', 'duration_ms', 'configuration_revision', 'quality_flags', 'bands', ...array_values(self::ENERGY), ...array_values(self::MAXIMA)]);

        // Ambiguity: more than one row for the same interval on this channel.
        $perInterval = [];

        foreach ($rows as $row) {
            $perInterval[$row->captured_at] = ($perInterval[$row->captured_at] ?? 0) + 1;
        }

        $buckets = [];

        foreach ($rows as $row) {
            $streamId = (int) $row->stream_id;
            $bucket = $buckets[$streamId] ??= $this->emptyBucket();
            $duration = (int) $row->duration_ms;
            $mask = (int) $row->quality_flags;

            $bucket['row_count']++;
            // Key 0 stands for "local defaults" (no configuration applied); revisions start at 1.
            $bucket['configuration_revisions'][(int) ($row->configuration_revision ?? 0)] = true;

            foreach (QualityFlag::fromMask($mask) as $flag) {
                $bucket['quality_counts'][$flag->value] = ($bucket['quality_counts'][$flag->value] ?? 0) + 1;
            }

            if ($perInterval[$row->captured_at] > 1) {
                $bucket['ambiguous_intervals'][$row->captured_at] = $duration;
                $bucket['quality_counts']['ambiguous_overlap'] = ($bucket['quality_counts']['ambiguous_overlap'] ?? 0) + 1;
                $buckets[$streamId] = $bucket;

                continue;
            }

            $bucket['covered_ms'] += $duration;

            if (QualityPolicy::excludesInterval($mask)) {
                $bucket['excluded_ms'] += $duration;
            }

            foreach (self::ENERGY as $prefix => $column) {
                $value = $row->{$column};

                if ($value === null) {
                    continue;
                }

                if (QualityPolicy::excludes(Metric::from($column), $mask)) {
                    $bucket[$prefix.'_excluded_ms'] += $duration;

                    continue;
                }

                $bucket[$prefix.'_energy_sum'] = ($bucket[$prefix.'_energy_sum'] ?? 0.0) + Decibels::energy((float) $value, $duration);
                $bucket[$prefix.'_valid_ms'] += $duration;
            }

            foreach (self::MAXIMA as $prefix => $column) {
                $value = $row->{$column};

                if ($value === null) {
                    continue;
                }

                if (QualityPolicy::excludes(Metric::from($column), $mask)) {
                    $bucket[$prefix.'_excluded_ms'] += $duration;

                    continue;
                }

                $bucket[$prefix.'_max'] = max($bucket[$prefix.'_max'] ?? -INF, (float) $value);
                $bucket[$prefix.'_valid_ms'] += $duration;
            }

            if ($row->bands !== null && ! QualityPolicy::excludes(Metric::LowFrequencyLeq, $mask)) {
                foreach (json_decode($row->bands, true) as $band) {
                    if ($band['level_db'] === null) {
                        continue;
                    }

                    $key = $band['center_hz'].'|'.$band['weighting'];
                    $bucket['bands'][$key]['center_hz'] = $band['center_hz'];
                    $bucket['bands'][$key]['weighting'] = $band['weighting'];
                    $bucket['bands'][$key]['energy_sum'] = ($bucket['bands'][$key]['energy_sum'] ?? 0.0) + Decibels::energy((float) $band['level_db'], $duration);
                    $bucket['bands'][$key]['valid_ms'] = ($bucket['bands'][$key]['valid_ms'] ?? 0) + $duration;
                }
            }

            $buckets[$streamId] = $bucket;
        }

        DB::transaction(function () use ($device, $deviceId, $channel, $minute, $buckets): void {
            $this->write($device->account_id, $deviceId, $channel, self::MINUTE, $minute, $buckets);

            $hour = $minute->startOfHour()->format('Y-m-d H:i:s');
            $this->maintenance->markMany([[
                'kind' => MaintenanceJobKind::RollupHour,
                'key' => $deviceId.'|'.$channel.'|'.$hour,
                'account_id' => (int) $device->account_id,
                'subject_type' => 'device',
                'subject_id' => $deviceId,
                'bucket_start' => $hour,
                'payload' => ['channel' => $channel],
            ]]);
        });
    }

    public function rebuildHour(int $deviceId, string $channel, CarbonImmutable $hour): void
    {
        $hour = $hour->utc()->startOfHour();
        $device = DB::table('devices')->where('id', $deviceId)->first(['id', 'account_id']);

        if ($device === null) {
            return;
        }

        $streamIds = DB::table('measurement_streams')->where('device_id', $deviceId)->where('channel', $channel)->pluck('id')->all();

        $minutes = $streamIds === [] ? collect() : DB::table('measurement_rollups')
            ->where('account_id', $device->account_id)
            ->where('device_id', $deviceId)
            ->where('resolution_seconds', self::MINUTE)
            ->whereIn('stream_id', $streamIds)
            ->where('bucket_start', '>=', $hour->format('Y-m-d H:i:s'))
            ->where('bucket_start', '<', $hour->addHour()->format('Y-m-d H:i:s'))
            ->get();

        $buckets = [];

        foreach ($minutes as $minute) {
            $streamId = (int) $minute->stream_id;
            $bucket = $buckets[$streamId] ??= $this->emptyBucket();

            foreach (['row_count', 'covered_ms', 'excluded_ms'] as $field) {
                $bucket[$field] += (int) $minute->{$field};
            }

            $bucket['ambiguous_ms_total'] = ($bucket['ambiguous_ms_total'] ?? 0) + (int) $minute->ambiguous_ms;

            foreach (array_keys(self::ENERGY) as $prefix) {
                if ($minute->{$prefix.'_energy_sum'} !== null) {
                    $bucket[$prefix.'_energy_sum'] = ($bucket[$prefix.'_energy_sum'] ?? 0.0) + (float) $minute->{$prefix.'_energy_sum'};
                }

                $bucket[$prefix.'_valid_ms'] += (int) $minute->{$prefix.'_valid_ms'};
                $bucket[$prefix.'_excluded_ms'] += (int) $minute->{$prefix.'_excluded_ms'};
            }

            foreach (array_keys(self::MAXIMA) as $prefix) {
                if ($minute->{$prefix.'_max'} !== null) {
                    $bucket[$prefix.'_max'] = max($bucket[$prefix.'_max'] ?? -INF, (float) $minute->{$prefix.'_max'});
                }

                $bucket[$prefix.'_valid_ms'] += (int) $minute->{$prefix.'_valid_ms'};
                $bucket[$prefix.'_excluded_ms'] += (int) $minute->{$prefix.'_excluded_ms'};
            }

            foreach (json_decode($minute->quality_counts ?? '{}', true) as $flag => $count) {
                $bucket['quality_counts'][$flag] = ($bucket['quality_counts'][$flag] ?? 0) + $count;
            }

            foreach (json_decode($minute->configuration_revisions ?? '[]', true) as $revision) {
                $bucket['configuration_revisions'][(int) ($revision ?? 0)] = true;
            }

            foreach (json_decode($minute->bands ?? '[]', true) as $band) {
                $key = $band['center_hz'].'|'.$band['weighting'];
                $bucket['bands'][$key]['center_hz'] = $band['center_hz'];
                $bucket['bands'][$key]['weighting'] = $band['weighting'];
                $bucket['bands'][$key]['energy_sum'] = ($bucket['bands'][$key]['energy_sum'] ?? 0.0) + $band['energy_sum'];
                $bucket['bands'][$key]['valid_ms'] = ($bucket['bands'][$key]['valid_ms'] ?? 0) + $band['valid_ms'];
            }

            $buckets[$streamId] = $bucket;
        }

        DB::transaction(fn () => $this->write($device->account_id, $deviceId, $channel, self::HOUR, $hour, $buckets));
    }

    /**
     * @param  array<int, array<string, mixed>>  $buckets
     */
    private function write(int $accountId, int $deviceId, string $channel, int $resolution, CarbonImmutable $bucketStart, array $buckets): void
    {
        $now = CarbonImmutable::now()->format('Y-m-d H:i:s.u');
        $start = $bucketStart->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($buckets as $streamId => $bucket) {
            $ambiguousMs = array_sum($bucket['ambiguous_intervals']) + ($bucket['ambiguous_ms_total'] ?? 0);
            $revisions = array_keys($bucket['configuration_revisions']);
            sort($revisions);
            $revisions = array_map(fn (int $revision): ?int => $revision === 0 ? null : $revision, $revisions);
            ksort($bucket['quality_counts']);
            $bands = array_values($bucket['bands']);
            usort($bands, fn (array $a, array $b): int => [$a['center_hz'], $a['weighting']] <=> [$b['center_hz'], $b['weighting']]);

            $row = [
                'account_id' => $accountId,
                'device_id' => $deviceId,
                'stream_id' => $streamId,
                'resolution_seconds' => $resolution,
                'bucket_start' => $start,
                'expected_ms' => $resolution * 1000,
                'covered_ms' => $bucket['covered_ms'],
                'excluded_ms' => $bucket['excluded_ms'],
                'ambiguous_ms' => $ambiguousMs,
                'row_count' => $bucket['row_count'],
                'quality_counts' => $bucket['quality_counts'] === [] ? null : json_encode($bucket['quality_counts']),
                'configuration_revisions' => json_encode($revisions),
                'bands' => $bands === [] ? null : json_encode($bands, JSON_PRESERVE_ZERO_FRACTION),
                'policy_version' => QualityPolicy::VERSION,
                'rebuilt_at' => $now,
            ];

            foreach (array_keys(self::ENERGY) as $prefix) {
                $row[$prefix.'_energy_sum'] = $bucket[$prefix.'_energy_sum'];
                $row[$prefix.'_valid_ms'] = $bucket[$prefix.'_valid_ms'];
                $row[$prefix.'_excluded_ms'] = $bucket[$prefix.'_excluded_ms'];
            }

            foreach (array_keys(self::MAXIMA) as $prefix) {
                $row[$prefix.'_max'] = $bucket[$prefix.'_max'];
                $row[$prefix.'_valid_ms'] = $bucket[$prefix.'_valid_ms'];
                $row[$prefix.'_excluded_ms'] = $bucket[$prefix.'_excluded_ms'];
            }

            $rows[] = $row;
        }

        $channelStreams = DB::table('measurement_streams')->where('device_id', $deviceId)->where('channel', $channel)->pluck('id')->all();
        $stale = array_diff($channelStreams, array_keys($buckets));

        if ($stale !== []) {
            DB::table('measurement_rollups')
                ->where('device_id', $deviceId)
                ->where('resolution_seconds', $resolution)
                ->where('bucket_start', $start)
                ->whereIn('stream_id', $stale)
                ->delete();
        }

        if ($rows !== []) {
            DB::table('measurement_rollups')->upsert(
                $rows,
                ['stream_id', 'resolution_seconds', 'bucket_start'],
                array_values(array_diff(array_keys($rows[0]), ['account_id', 'device_id', 'stream_id', 'resolution_seconds', 'bucket_start'])),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyBucket(): array
    {
        $bucket = [
            'row_count' => 0,
            'covered_ms' => 0,
            'excluded_ms' => 0,
            'ambiguous_intervals' => [],
            'quality_counts' => [],
            'configuration_revisions' => [],
            'bands' => [],
        ];

        foreach (array_keys(self::ENERGY) as $prefix) {
            $bucket[$prefix.'_energy_sum'] = null;
            $bucket[$prefix.'_valid_ms'] = 0;
            $bucket[$prefix.'_excluded_ms'] = 0;
        }

        foreach (array_keys(self::MAXIMA) as $prefix) {
            $bucket[$prefix.'_max'] = null;
            $bucket[$prefix.'_valid_ms'] = 0;
            $bucket[$prefix.'_excluded_ms'] = 0;
        }

        return $bucket;
    }
}
