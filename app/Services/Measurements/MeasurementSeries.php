<?php

namespace App\Services\Measurements;

use App\Enums\Metric;
use App\Enums\QualityFlag;
use App\Models\Device;
use App\Models\MeasurementStream;
use App\Support\Decibels;
use App\Support\Rfc3339;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bounded chart series (spec §12): raw seconds for short zooms, minute
 * rollups for day views, hour rollups for long ranges, never more than
 * max_points_per_series points per series. When the requested resolution
 * would exceed the budget an explicitly coarser resolution is returned.
 *
 * Each stream (deployment/profile/calibration identity) is its own series.
 * Buckets without data are gaps (null), never zero.
 */
class MeasurementSeries
{
    /** Resolution ladder: seconds => [source, label]. */
    public const RESOLUTIONS = [
        1 => ['raw', '1 second'],
        10 => ['raw', '10 seconds'],
        60 => ['minute', '1 minute'],
        300 => ['minute', '5 minutes'],
        900 => ['minute', '15 minutes'],
        3600 => ['hour', '1 hour'],
        21600 => ['hour', '6 hours'],
        86400 => ['hour', '1 day'],
    ];

    private const ROLLUP_PREFIX = [
        'laeq_db' => 'laeq',
        'lceq_db' => 'lceq',
        'low_frequency_leq_db' => 'lf',
        'rms_dbfs' => 'dbfs',
        'lafmax_db' => 'lafmax',
        'lcpeak_db' => 'lcpeak',
    ];

    /**
     * @return array<string, mixed>
     */
    public function forDevice(Device $device, string $channel, CarbonImmutable $from, CarbonImmutable $to, Metric $metric, ?int $preferredResolution = null): array
    {
        if (! $to->greaterThan($from)) {
            throw new InvalidArgumentException('The end of the range must be after its start.');
        }

        $from = $from->utc();
        $to = $to->utc();
        $resolution = $this->chooseResolution($to->getTimestamp() - $from->getTimestamp(), $preferredResolution);
        [$source, $label] = self::RESOLUTIONS[$resolution];

        $streams = MeasurementStream::query()
            ->with(['deployment', 'profile', 'calibration'])
            ->where('account_id', $device->account_id)
            ->where('device_id', $device->id)
            ->where('channel', $channel)
            ->get()
            ->keyBy('id');

        $buckets = match ($source) {
            'raw' => $this->raw($device, $channel, $from, $to, $metric, $resolution),
            default => $this->rollups($device, $streams->keys()->all(), $from, $to, $metric, $resolution, $source === 'minute' ? 60 : 3600),
        };

        $series = [];

        foreach ($buckets as $streamId => $points) {
            $stream = $streams[$streamId] ?? null;

            if ($stream === null) {
                continue;
            }

            ksort($points);
            $series[] = [
                'stream_id' => $streamId,
                'label' => $stream->label(),
                'calibration_state' => $stream->calibration_state->value,
                'calibration_label' => $stream->calibration_state->getLabel(),
                'points' => $this->withGaps(array_values($points), $resolution),
            ];
        }

        return [
            'resolution' => [
                'seconds' => $resolution,
                'label' => $label,
                'source' => $source,
                'requested_seconds' => $preferredResolution,
                'coarsened' => $preferredResolution !== null && $resolution > $preferredResolution,
            ],
            'from' => Rfc3339::format($from),
            'to' => Rfc3339::format($to),
            'expected_ms_per_bucket' => $resolution * 1000,
            'metric' => [
                'key' => $metric->value,
                'label' => $metric->getLabel(),
                'unit' => $metric->unit(),
                'axis' => $metric->labelWithUnit(),
                'aggregation' => $metric->isEnergy() ? 'energy' : 'max',
            ],
            'series' => $series,
            'quality_policy' => QualityPolicy::VERSION,
        ];
    }

    public function chooseResolution(int $spanSeconds, ?int $preferred = null): int
    {
        $budget = (int) config('noise.dashboard.max_points_per_series');

        foreach (array_keys(self::RESOLUTIONS) as $seconds) {
            if ($preferred !== null && $seconds < $preferred) {
                continue;
            }

            if (intdiv($spanSeconds + $seconds - 1, $seconds) <= $budget) {
                return $seconds;
            }
        }

        return array_key_last(self::RESOLUTIONS);
    }

    /**
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function raw(Device $device, string $channel, CarbonImmutable $from, CarbonImmutable $to, Metric $metric, int $resolution): array
    {
        $column = $metric->value;
        $usable = QualityPolicy::sqlUsable($metric);
        $lafmaxUsable = QualityPolicy::sqlUsable(Metric::LAFmax);
        $base = DB::table('measurements')
            ->where('account_id', $device->account_id)
            ->where('device_id', $device->id)
            ->where('channel', $channel)
            ->where('captured_at', '>=', Rfc3339::toDatabase($from))
            ->where('captured_at', '<', Rfc3339::toDatabase($to));

        $result = [];

        if ($resolution === 1) {
            $rows = $base->orderBy('captured_at')->get(['stream_id', 'captured_at', 'duration_ms', 'quality_flags', $column, 'lafmax_db']);

            foreach ($rows as $row) {
                $time = CarbonImmutable::parse($row->captured_at, 'UTC');
                $mask = (int) $row->quality_flags;
                $excluded = $row->{$column} !== null && QualityPolicy::excludes($metric, $mask);
                $t = $time->getTimestampMs();

                $result[(int) $row->stream_id][$t] = [
                    't' => $t,
                    'v' => $row->{$column} === null || $excluded ? null : round((float) $row->{$column}, 2),
                    'm' => $row->lafmax_db === null || QualityPolicy::excludes(Metric::LAFmax, $mask) ? null : round((float) $row->lafmax_db, 2),
                    'raw' => $row->{$column} === null ? null : (float) $row->{$column},
                    'valid_ms' => $row->{$column} === null || $excluded ? 0 : (int) $row->duration_ms,
                    'expected_ms' => 1000,
                    'excluded_ms' => $excluded ? (int) $row->duration_ms : 0,
                    'flags' => QualityFlag::valuesFromMask($mask),
                ];
            }

            return $result;
        }

        $valueSql = $metric->isEnergy()
            ? "SUM(IF({$usable} AND {$column} IS NOT NULL, duration_ms * POW(10, {$column} / 10), 0)) AS energy, MAX(NULL) AS maximum"
            : "NULL AS energy, MAX(IF({$usable}, {$column}, NULL)) AS maximum";

        $rows = $base
            ->selectRaw("stream_id, FLOOR(UNIX_TIMESTAMP(captured_at) / {$resolution}) AS slot, {$valueSql},
                SUM(IF({$usable} AND {$column} IS NOT NULL, duration_ms, 0)) AS valid_ms,
                SUM(IF(NOT ({$usable}) AND {$column} IS NOT NULL, duration_ms, 0)) AS excluded_ms,
                MAX(IF({$lafmaxUsable}, lafmax_db, NULL)) AS lafmax_max,
                BIT_OR(quality_flags) AS flags")
            ->groupBy('stream_id', 'slot')
            ->get();

        foreach ($rows as $row) {
            $t = (int) $row->slot * $resolution * 1000;
            $value = $metric->isEnergy() ? Decibels::leq((float) $row->energy, (int) $row->valid_ms) : ($row->maximum === null ? null : (float) $row->maximum);

            $result[(int) $row->stream_id][$t] = [
                't' => $t,
                'v' => $value === null ? null : round($value, 2),
                'm' => $row->lafmax_max === null ? null : round((float) $row->lafmax_max, 2),
                'valid_ms' => (int) $row->valid_ms,
                'expected_ms' => $resolution * 1000,
                'excluded_ms' => (int) $row->excluded_ms,
                'flags' => QualityFlag::valuesFromMask((int) $row->flags),
            ];
        }

        return $result;
    }

    /**
     * @param  list<int>  $streamIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function rollups(Device $device, array $streamIds, CarbonImmutable $from, CarbonImmutable $to, Metric $metric, int $resolution, int $sourceResolution): array
    {
        if ($streamIds === []) {
            return [];
        }

        $prefix = self::ROLLUP_PREFIX[$metric->value];
        $valueSql = $metric->isEnergy()
            ? "SUM({$prefix}_energy_sum) AS energy, NULL AS maximum"
            : "NULL AS energy, MAX({$prefix}_max) AS maximum";

        $rows = DB::table('measurement_rollups')
            ->where('account_id', $device->account_id)
            ->where('device_id', $device->id)
            ->whereIn('stream_id', $streamIds)
            ->where('resolution_seconds', $sourceResolution)
            ->where('bucket_start', '>=', $from->copy()->startOfSecond()->format('Y-m-d H:i:s'))
            ->where('bucket_start', '<', $to->format('Y-m-d H:i:s'))
            ->selectRaw("stream_id, FLOOR(UNIX_TIMESTAMP(bucket_start) / {$resolution}) AS slot, {$valueSql},
                SUM({$prefix}_valid_ms) AS valid_ms, SUM({$prefix}_excluded_ms) AS excluded_ms,
                SUM(covered_ms) AS covered_ms, SUM(ambiguous_ms) AS ambiguous_ms, MAX(lafmax_max) AS lafmax_max,
                SUM(row_count) AS row_count")
            ->groupBy('stream_id', 'slot')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $t = (int) $row->slot * $resolution * 1000;
            $value = $metric->isEnergy() ? Decibels::leq($row->energy === null ? null : (float) $row->energy, (int) $row->valid_ms) : ($row->maximum === null ? null : (float) $row->maximum);

            $result[(int) $row->stream_id][$t] = [
                't' => $t,
                'v' => $value === null ? null : round($value, 2),
                'm' => $row->lafmax_max === null ? null : round((float) $row->lafmax_max, 2),
                'valid_ms' => (int) $row->valid_ms,
                'expected_ms' => $resolution * 1000,
                'excluded_ms' => (int) $row->excluded_ms,
                'covered_ms' => (int) $row->covered_ms,
                'ambiguous_ms' => (int) $row->ambiguous_ms,
                'flags' => (int) $row->ambiguous_ms > 0 ? ['ambiguous_overlap'] : [],
            ];
        }

        return $result;
    }

    /**
     * Insert explicit null points so lines never bridge outages.
     *
     * @param  list<array<string, mixed>>  $points
     * @return list<array<string, mixed>>
     */
    private function withGaps(array $points, int $resolution): array
    {
        $step = $resolution * 1000;
        $result = [];
        $previous = null;

        foreach ($points as $point) {
            if ($previous !== null && $step < $point['t'] - $previous) {
                $result[] = ['t' => $previous + $step, 'v' => null, 'm' => null, 'gap' => true];
            }

            $result[] = $point;
            $previous = $point['t'];
        }

        return $result;
    }
}
