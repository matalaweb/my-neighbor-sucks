<?php

namespace App\Services\Measurements;

use App\Enums\Metric;
use App\Enums\ReviewStatus;
use App\Models\Device;
use App\Models\NoiseEvent;
use App\Support\Rfc3339;
use Carbon\CarbonImmutable;

/**
 * Builds the time-chart payload consumed by resources/js/noise-charts.js:
 * a measurement series for one device channel plus shaded event windows.
 */
class DeviceChart
{
    public function __construct(private readonly MeasurementSeries $measurementSeries) {}

    /**
     * @return array{series: array<string, mixed>, chart: array<string, mixed>}
     */
    public function build(Device $device, string $channel, Metric $metric, CarbonImmutable $from, CarbonImmutable $to, bool $showMaxima): array
    {
        $series = $this->measurementSeries->forDevice($device, $channel, $from, $to, $metric);

        $events = NoiseEvent::query()
            ->where('account_id', $device->account_id)
            ->where('device_id', $device->id)
            ->where('started_at', '<', Rfc3339::toDatabase($to))
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', Rfc3339::toDatabase($from)))
            ->orderBy('started_at')
            ->limit(500)
            ->get(['id', 'uuid', 'started_at', 'ended_at', 'review_status']);

        return [
            'series' => $series,
            'chart' => [
                'timezone' => $device->property->timezone,
                'metric' => $series['metric'],
                'resolution' => $series['resolution'],
                'series' => $series['series'],
                'showMaxima' => $showMaxima && $metric->isEnergy(),
                'range' => ['from' => $from->getTimestampMs(), 'to' => $to->getTimestampMs()],
                'events' => $events->map(fn (NoiseEvent $event): array => [
                    'start' => $event->started_at->getTimestampMs(),
                    'end' => $event->ended_at?->getTimestampMs(),
                    'confirmed' => $event->review_status === ReviewStatus::ConfirmedDisturbance,
                ])->all(),
            ],
        ];
    }
}
