<?php

use App\Enums\CalibrationState;
use App\Enums\Metric;
use App\Filament\Resources\NoiseEvents\NoiseEventResource;
use App\Models\NoiseEvent;
use App\Services\Measurements\DashboardSummary;
use App\Services\Measurements\MeasurementSeries;
use App\Services\Measurements\RebuildRollups;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\DeviceFixture;

it('converts local calendar days to UTC ranges with 23 and 25 hour DST days', function (): void {
    [$springStart, $springEnd] = LocalTime::dayRange('2026-03-08', 'America/Chicago');
    [$fallStart, $fallEnd] = LocalTime::dayRange('2026-11-01', 'America/Chicago');
    [$normalStart, $normalEnd] = LocalTime::dayRange('2026-10-08', 'America/Chicago');

    expect($springStart->diffInHours($springEnd))->toEqual(23)
        ->and($fallStart->diffInHours($fallEnd))->toEqual(25)
        ->and($normalStart->diffInHours($normalEnd))->toEqual(24)
        ->and($fallStart->toIso8601ZuluString())->toBe('2026-11-01T05:00:00Z');
});

it('keeps UTC ordering and unambiguous labels across the fall-back hour', function (): void {
    CarbonImmutable::setTestNow('2026-11-01T12:00:00Z');
    $fixture = DeviceFixture::create();

    // 01:30 CDT (06:30Z) and 01:30 CST (07:30Z) are the same local wall time.
    $first = $fixture->records(60, CarbonImmutable::parse('2026-11-01T06:30:00Z'), 1, fn ($r) => [...$r, 'laeq_db' => 40.0]);
    $second = $fixture->records(60, CarbonImmutable::parse('2026-11-01T07:30:00Z'), 1001, fn ($r) => [...$r, 'laeq_db' => 60.0]);
    $this->devicePost($fixture, 'measurements/batches', $fixture->batch($second))->assertCreated();
    $this->devicePost($fixture, 'measurements/batches', $fixture->batch($first))->assertCreated();
    app(RebuildRollups::class)->processDirty();

    [$from, $to] = LocalTime::dayRange('2026-11-01', 'America/Chicago');
    $series = app(MeasurementSeries::class)->forDevice($fixture->device, 'mic-1', $from, $to, Metric::LAeq);
    $points = collect($series['series'][0]['points'])->reject(fn ($p) => $p['gap'] ?? false)->values();
    $times = $points->pluck('t')->all();
    $sorted = $times;
    sort($sorted);

    expect($series['resolution']['seconds'])->toBe(60)
        ->and($times)->toBe($sorted)
        ->and($points->first()['v'])->toEqualWithDelta(40.0, 0.001)
        ->and($points->last()['v'])->toEqualWithDelta(60.0, 0.001)
        ->and(LocalTime::display(CarbonImmutable::parse('2026-11-01T06:30:00Z'), 'America/Chicago'))->toContain('01:30:00 CDT (-05:00)')
        ->and(LocalTime::display(CarbonImmutable::parse('2026-11-01T07:30:00Z'), 'America/Chicago'))->toContain('01:30:00 CST (-06:00)');

    $summary = app(DashboardSummary::class)->device($fixture->device->fresh(), '2026-11-01');
    expect($summary['today'][0]['day_minutes'])->toBe(1500)
        ->and($summary['today'][0]['laeq_valid_minutes'])->toBe(2);
});

it('bounds chart points and returns an explicit coarser resolution', function (): void {
    $series = app(MeasurementSeries::class);

    expect($series->chooseResolution(3600))->toBe(10)
        ->and($series->chooseResolution(900))->toBe(1)
        ->and($series->chooseResolution(86400))->toBe(60)
        ->and($series->chooseResolution(86400, 1))->toBe(60)
        ->and($series->chooseResolution(30 * 86400))->toBe(3600)
        ->and($series->chooseResolution(400 * 86400))->toBe(21600)
        ->and($series->chooseResolution(600 * 86400))->toBe(86400);

    foreach ([60, 3600, 86400, 7 * 86400, 30 * 86400, 90 * 86400] as $span) {
        $resolution = $series->chooseResolution($span);
        expect(intdiv($span + $resolution - 1, $resolution))->toBeLessThanOrEqual(2000);
    }
});

it('renders gaps as null points instead of zero or bridged lines', function (): void {
    CarbonImmutable::setTestNow('2026-10-08T13:00:00Z');
    $fixture = DeviceFixture::create();
    $start = CarbonImmutable::parse('2026-10-08T12:00:00Z');
    $this->devicePost($fixture, 'measurements/batches', $fixture->batch($fixture->records(10, $start)))->assertCreated();
    $this->devicePost($fixture, 'measurements/batches', $fixture->batch($fixture->records(10, $start->addSeconds(30), 100)))->assertCreated();

    $series = app(MeasurementSeries::class)->forDevice($fixture->device, 'mic-1', $start, $start->addMinutes(2), Metric::LAeq);
    $points = $series['series'][0]['points'];
    $gap = collect($points)->firstWhere('gap', true);

    expect($series['resolution']['seconds'])->toBe(1)
        ->and($gap)->not->toBeNull()
        ->and($gap['v'])->toBeNull()
        ->and(collect($points)->whereStrict('v', 0.0)->count())->toBe(0)
        ->and(count($points))->toBe(21);
});

it('labels stale readings, uncalibrated dBFS values, and unavailable recordings on screens', function (): void {
    CarbonImmutable::setTestNow('2026-10-08T13:00:00Z');
    $fixture = DeviceFixture::create(CalibrationState::Uncalibrated);
    $start = CarbonImmutable::parse('2026-10-08T12:50:00Z');
    $this->devicePost($fixture, 'measurements/batches', $fixture->batch($fixture->records(5, $start)))->assertCreated();
    $eventId = (string) Str::uuid();
    $this->devicePost($fixture, 'events', $fixture->event($eventId, 1, $start->addSecond(), $start->addSeconds(3)))->assertCreated();
    $this->flushHeaders();

    $this->actingAs($fixture->owner, 'web')
        ->get("/app/{$fixture->account->uuid}")
        ->assertOk()
        ->assertSee('Stale (older than 90 s)')
        ->assertSee('dBFS (digital level, not dBA)')
        ->assertSee('Uncalibrated');

    $event = NoiseEvent::query()->where('uuid', $eventId)->first();

    $this->get(NoiseEventResource::getUrl('view', ['record' => $event, 'tenant' => $fixture->account]))
        ->assertOk()
        ->assertSee('A recording is expected; the device has not declared it yet.')
        ->assertDontSee('<audio', false);
});
