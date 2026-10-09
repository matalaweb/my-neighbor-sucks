<?php

namespace App\Services\Measurements;

use App\Enums\CalibrationState;
use App\Enums\Metric;
use App\Models\Device;
use App\Models\NoiseEvent;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only projection of one device for its public share link. Only
 * whitelisted, display-ready scalars leave this class: no property name or
 * address, placement, credentials, heartbeat details, recordings, source
 * labels, annotations, or identifiers.
 */
class PublicDeviceDashboard
{
    /** @var array<string, array{label: string, seconds: int}> */
    public const RANGES = [
        '1h' => ['label' => '1 hour', 'seconds' => 3600],
        '24h' => ['label' => '24 hours', 'seconds' => 86400],
        '7d' => ['label' => '7 days', 'seconds' => 604800],
        '30d' => ['label' => '30 days', 'seconds' => 2592000],
    ];

    public const DEFAULT_RANGE = '24h';

    public const RECENT_EVENT_LIMIT = 25;

    public function __construct(
        private readonly DashboardSummary $summaries,
        private readonly DeviceChart $deviceChart,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Device $device, string $range): array
    {
        $range = array_key_exists($range, self::RANGES) ? $range : self::DEFAULT_RANGE;

        return Cache::remember(
            "public-dashboard:{$device->id}:{$range}",
            (int) config('noise.sharing.cache_seconds'),
            fn (): array => $this->compute($device, $range),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(Device $device, string $range): array
    {
        $device->loadMissing(['property', 'latestHeartbeat']);
        $now = CarbonImmutable::now();
        $timezone = $device->property->timezone;
        $today = $now->setTimezone($timezone)->format('Y-m-d');

        $summary = $this->summaries->device($device, $today, $now);
        $totals = $this->summaries->eventTotals($device->property, $today, $device);
        $todayStream = $summary['today'][0] ?? null;
        $calibration = $summary['latest']['calibration_state'] ?? $todayStream['calibration_state'] ?? null;

        $channel = $device->streams()->value('channel') ?? 'mic-1';
        ['chart' => $chart] = $this->deviceChart->build($device, $channel, Metric::LAeq, $now->subSeconds(self::RANGES[$range]['seconds']), $now, showMaxima: true);

        return [
            'title' => $device->publicTitle(),
            'timezone' => $timezone,
            'timezone_abbreviation' => $now->setTimezone($timezone)->format('T'),
            'online' => $summary['online'],
            'last_contact' => LocalTime::age($device->last_contact_at, $now),
            'latest' => $summary['latest'] === null ? null : [
                'laeq' => $summary['latest']['laeq'],
                'descriptor' => self::describeLevel($summary['latest']['laeq']),
                'scale_percent' => $summary['latest']['laeq'] === null ? null : round(min(100, max(0, ($summary['latest']['laeq'] - 30) / 70 * 100)), 1),
                'age' => LocalTime::age($summary['latest']['captured_at'], $now),
                'stale' => $summary['latest']['stale'],
            ],
            'today' => [
                'peak' => $todayStream['lafmax_max'] ?? null,
                'leq' => $todayStream['laeq'] ?? null,
                'coverage' => $todayStream === null || $todayStream['elapsed_minutes'] === 0
                    ? null
                    : min(100, (int) round(100 * $todayStream['laeq_valid_minutes'] / $todayStream['elapsed_minutes'])),
            ],
            'events_today' => ['all' => $totals['all'], 'confirmed' => $totals['confirmed']],
            'calibration' => $calibration instanceof CalibrationState ? [
                'state' => $calibration->value,
                'label' => $calibration->getLabel(),
                'description' => $calibration->getDescription(),
            ] : null,
            'range' => $range,
            'chart' => $this->publicChart($chart),
            'events' => $this->recentEvents($device, $timezone),
            'quality_policy' => QualityPolicy::VERSION,
            'generated_at' => $now->setTimezone($timezone)->format('M j, g:i:s A T'),
        ];
    }

    /**
     * Replace internal stream labels (channel, placement and profile
     * revisions) and identifiers with the calibration label alone.
     *
     * @param  array<string, mixed>  $chart
     * @return array<string, mixed>
     */
    private function publicChart(array $chart): array
    {
        $chart['series'] = array_map(fn (array $series): array => [
            'label' => $series['calibration_label'],
            'calibration_state' => $series['calibration_state'],
            'calibration_label' => $series['calibration_label'],
            'points' => $series['points'],
        ], $chart['series']);

        return $chart;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentEvents(Device $device, string $timezone): array
    {
        return NoiseEvent::query()
            ->where('account_id', $device->account_id)
            ->where('device_id', $device->id)
            ->latest('started_at')
            ->limit(self::RECENT_EVENT_LIMIT)
            ->get(['id', 'started_at', 'ended_at', 'detection_state', 'quality_flags', 'review_status', 'trigger_value_db', 'baseline_db', 'agent_summary', 'server_summary'])
            ->map(function (NoiseEvent $event) use ($timezone): array {
                $started = $event->started_at->setTimezone($timezone);
                $peak = $event->server_summary['metrics']['lafmax_db'] ?? $event->agent_summary['lafmax_db'] ?? null;

                return [
                    'day' => $started->format('l, M j'),
                    'time' => $started->format('g:i:s A'),
                    'duration' => $this->duration($event),
                    'peak' => $peak === null ? null : round((float) $peak, 1),
                    'above_baseline' => $event->levelAboveBaseline(),
                    'status' => $event->review_status->value,
                    'status_label' => $event->review_status->getLabel(),
                ];
            })
            ->all();
    }

    /**
     * Plain-language description of an A-weighted level for non-specialists.
     */
    public static function describeLevel(?float $laeq): string
    {
        return match (true) {
            $laeq === null => 'Absolute level unavailable',
            $laeq < 40 => 'Quiet',
            $laeq < 55 => 'Moderate',
            $laeq < 70 => 'Loud',
            $laeq < 85 => 'Very loud',
            default => 'Extremely loud',
        };
    }

    private function duration(NoiseEvent $event): string
    {
        $milliseconds = $event->durationMs();

        if ($event->isOpen() || $milliseconds === null) {
            return 'Ongoing';
        }

        $seconds = max(1, (int) round($milliseconds / 1000));
        $label = $seconds < 60 ? "{$seconds} s" : intdiv($seconds, 60).' min '.($seconds % 60).' s';

        return $event->observationInterrupted() ? "≥ {$label}" : $label;
    }
}
