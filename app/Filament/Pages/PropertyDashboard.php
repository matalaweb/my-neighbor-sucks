<?php

namespace App\Filament\Pages;

use App\Enums\Metric;
use App\Models\Account;
use App\Models\Device;
use App\Models\NoiseEvent;
use App\Models\Property;
use App\Services\Measurements\DashboardSummary;
use App\Services\Measurements\MeasurementSeries;
use App\Support\LocalTime;
use App\Support\Rfc3339;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Near-real-time property dashboard. Polls every 15 s while visible; it is
 * not a live sound meter.
 */
class PropertyDashboard extends Dashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Monitoring';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Property dashboard';

    protected string $view = 'filament.pages.property-dashboard';

    #[Url(as: 'property')]
    public ?string $propertyUuid = null;

    #[Url(as: 'device')]
    public ?string $deviceUuid = null;

    #[Url]
    public ?string $date = null;

    #[Url]
    public string $range = 'day';

    #[Url]
    public string $metric = 'laeq_db';

    public bool $showMaxima = true;

    public bool $showTable = false;

    public function mount(): void
    {
        $property = $this->selectedProperty();
        $this->propertyUuid ??= $property?->uuid;
        $this->date ??= CarbonImmutable::now($property?->timezone ?? config('noise.default_timezone'))->format('Y-m-d');

        if (Metric::tryFrom($this->metric) === null) {
            $this->metric = Metric::LAeq->value;
        }
    }

    public function account(): Account
    {
        /** @var Account */
        return Filament::getTenant();
    }

    /** @return Collection<int, Property> */
    public function properties(): Collection
    {
        return $this->account()->properties()->whereNull('archived_at')->orderBy('name')->get();
    }

    public function selectedProperty(): ?Property
    {
        $properties = $this->properties();

        return $properties->firstWhere('uuid', $this->propertyUuid) ?? $properties->first();
    }

    /** @return Collection<int, Device> */
    public function devices(): Collection
    {
        $property = $this->selectedProperty();

        if ($property === null) {
            return collect();
        }

        return $property->devices()->with(['latestHeartbeat', 'property'])->where('status', 'active')->orderBy('name')->get();
    }

    public function selectedDevice(): ?Device
    {
        $devices = $this->devices();

        return $devices->firstWhere('uuid', $this->deviceUuid) ?? $devices->first();
    }

    public function shiftDate(int $days): void
    {
        $this->date = CarbonImmutable::parse($this->date)->addDays($days)->format('Y-m-d');
        $this->range = 'day';
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function chartRange(): array
    {
        $timezone = $this->selectedProperty()?->timezone ?? config('noise.default_timezone');
        $now = CarbonImmutable::now();

        return match ($this->range) {
            'hour' => [$now->subHour(), $now],
            '15m' => [$now->subMinutes(15), $now],
            '6h' => [$now->subHours(6), $now],
            'week' => [$now->subDays(7), $now],
            '30d' => [$now->subDays(30), $now],
            default => LocalTime::dayRange($this->date, $timezone),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $property = $this->selectedProperty();
        $device = $this->selectedDevice();

        if ($property === null || $device === null) {
            return ['property' => $property, 'device' => $device, 'summary' => null];
        }

        $metric = Metric::from($this->metric);
        [$from, $to] = $this->chartRange();
        $summaries = app(DashboardSummary::class);
        $channel = $device->streams()->value('channel') ?? 'mic-1';

        $series = app(MeasurementSeries::class)->forDevice($device, $channel, $from, $to, $metric);

        $events = NoiseEvent::query()
            ->where('account_id', $device->account_id)
            ->where('device_id', $device->id)
            ->where('started_at', '<', Rfc3339::toDatabase($to))
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', Rfc3339::toDatabase($from)))
            ->orderBy('started_at')
            ->limit(500)
            ->get(['id', 'uuid', 'started_at', 'ended_at', 'review_status']);

        $chart = [
            'timezone' => $property->timezone,
            'metric' => $series['metric'],
            'resolution' => $series['resolution'],
            'series' => $series['series'],
            'showMaxima' => $this->showMaxima && $metric->isEnergy(),
            'range' => ['from' => $from->getTimestampMs(), 'to' => $to->getTimestampMs()],
            'events' => $events->map(fn (NoiseEvent $event): array => [
                'start' => $event->started_at->getTimestampMs(),
                'end' => $event->ended_at?->getTimestampMs(),
                'confirmed' => $event->review_status->value === 'confirmed_disturbance',
            ])->all(),
        ];

        return [
            'property' => $property,
            'device' => $device,
            'channel' => $channel,
            'summary' => $summaries->device($device, $this->date),
            'totals' => $summaries->eventTotals($property, $this->date),
            'series' => $series,
            'chart' => $chart,
            'chartKey' => md5(json_encode($chart)),
            'recentEvents' => NoiseEvent::query()
                ->where('account_id', $property->account_id)
                ->where('property_id', $property->id)
                ->with('device')
                ->latest('started_at')
                ->limit(10)
                ->get(),
            'metrics' => Metric::cases(),
            'chartRange' => [$from, $to],
        ];
    }
}
