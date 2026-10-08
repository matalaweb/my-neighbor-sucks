<?php

use App\Enums\CalibrationState;
use App\Enums\MaintenanceJobKind;
use App\Models\MeasurementRollup;
use App\Services\Devices\ProvenanceRecords;
use App\Services\Maintenance\MaintenanceQueue;
use App\Services\Measurements\RebuildRollups;
use App\Support\Decibels;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeviceFixture;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-08T14:00:00Z');
    $this->fixture = DeviceFixture::create();
    $this->start = CarbonImmutable::parse('2026-10-08T12:15:00Z');
});

function rollup(int $resolution, string $bucket): ?MeasurementRollup
{
    return MeasurementRollup::query()->where('resolution_seconds', $resolution)->where('bucket_start', $bucket)->first();
}

it('energy-averages a minute, keeps maxima separate, and does not count missing time', function (): void {
    $records = [
        $this->fixture->record(1, $this->start, ['laeq_db' => 40.0, 'lafmax_db' => 45.0]),
        $this->fixture->record(2, $this->start->addSeconds(1), ['laeq_db' => 60.0, 'lafmax_db' => 72.5]),
        $this->fixture->record(3, $this->start->addSeconds(2), ['laeq_db' => 60.0, 'lafmax_db' => 61.0]),
        $this->fixture->record(4, $this->start->addSeconds(3), ['laeq_db' => 60.0, 'lafmax_db' => 63.0]),
    ];

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($records))->assertCreated();
    app(RebuildRollups::class)->processDirty();

    $minute = rollup(60, '2026-10-08 12:15:00');

    expect(Decibels::leq($minute->laeq_energy_sum, $minute->laeq_valid_ms))->toEqualWithDelta(58.7651, 0.0001)
        ->and($minute->lafmax_max)->toBe(72.5)
        ->and($minute->laeq_valid_ms)->toBe(4000)
        ->and($minute->covered_ms)->toBe(4000)
        ->and($minute->expected_ms)->toBe(60000);

    $hour = rollup(3600, '2026-10-08 12:00:00');
    expect(Decibels::leq($hour->laeq_energy_sum, $hour->laeq_valid_ms))->toEqualWithDelta(58.7651, 0.0001)
        ->and($hour->expected_ms)->toBe(3600000)
        ->and($hour->laeq_valid_ms)->toBe(4000);
});

it('repairs minute and hour rollups after out-of-order arrival without double counting', function (): void {
    $late = $this->fixture->records(30, $this->start->addSeconds(30), 31, fn (array $r) => [...$r, 'laeq_db' => 60.0]);
    $early = $this->fixture->records(30, $this->start, 1, fn (array $r) => [...$r, 'laeq_db' => 40.0]);
    $nextHour = $this->fixture->records(10, CarbonImmutable::parse('2026-10-08T13:00:00Z'), 1001, fn (array $r) => [...$r, 'laeq_db' => 50.0]);

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($late))->assertCreated();
    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($nextHour))->assertCreated();
    app(RebuildRollups::class)->processDirty();

    expect(rollup(60, '2026-10-08 12:15:00')->laeq_valid_ms)->toBe(30000);

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($early))->assertCreated();
    // Replaying an already-stored batch must not change anything.
    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($late))->assertCreated()->assertJson(['inserted_count' => 0, 'duplicate_count' => 30]);
    app(RebuildRollups::class)->processDirty();
    app(RebuildRollups::class)->processDirty();

    $minute = rollup(60, '2026-10-08 12:15:00');
    $hour = rollup(3600, '2026-10-08 12:00:00');

    expect($minute->laeq_valid_ms)->toBe(60000)
        ->and(Decibels::leq($minute->laeq_energy_sum, $minute->laeq_valid_ms))->toEqualWithDelta(57.0329, 0.0001)
        ->and($hour->laeq_valid_ms)->toBe(60000)
        ->and(Decibels::leq($hour->laeq_energy_sum, $hour->laeq_valid_ms))->toEqualWithDelta(57.0329, 0.0001)
        ->and(rollup(3600, '2026-10-08 13:00:00')->laeq_valid_ms)->toBe(10000)
        ->and(DB::table('maintenance_jobs')->count())->toBe(0);
});

it('excludes values under the quality policy, counts excluded time, and keeps raw values', function (): void {
    $records = [
        $this->fixture->record(1, $this->start, ['laeq_db' => 50.0]),
        $this->fixture->record(2, $this->start->addSecond(), ['laeq_db' => 95.0, 'lafmax_db' => 101.0, 'quality_flags' => ['clipping']]),
        $this->fixture->record(3, $this->start->addSeconds(2), ['laeq_db' => 50.0, 'rms_dbfs' => -20.0, 'quality_flags' => ['invalid_calibration']]),
        $this->fixture->record(4, $this->start->addSeconds(3), ['laeq_db' => null, 'null_reasons' => ['laeq_db' => 'processing_gap']]),
    ];

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($records))->assertCreated();
    app(RebuildRollups::class)->processDirty();

    $minute = rollup(60, '2026-10-08 12:15:00');

    expect($minute->laeq_valid_ms)->toBe(1000)
        ->and($minute->laeq_excluded_ms)->toBe(2000)
        ->and(Decibels::leq($minute->laeq_energy_sum, $minute->laeq_valid_ms))->toEqualWithDelta(50.0, 0.0001)
        ->and($minute->lafmax_max)->toBe(50.0)
        ->and($minute->dbfs_valid_ms)->toBe(3000)
        ->and($minute->excluded_ms)->toBe(1000)
        ->and($minute->quality_counts)->toBe(['clipping' => 1, 'invalid_calibration' => 1])
        ->and(DB::table('measurements')->where('sequence', 2)->value('laeq_db'))->toBe(95.0);
});

it('flags and excludes overlapping intervals from different boots', function (): void {
    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($this->fixture->records(5, $this->start)))->assertCreated();

    $otherBoot = $this->fixture->records(2, $this->start->addSeconds(3), 1, fn (array $r) => [...$r, 'boot_id' => '11111111-1111-4111-8111-111111111111']);
    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($otherBoot))->assertCreated();
    app(RebuildRollups::class)->processDirty();

    $minute = rollup(60, '2026-10-08 12:15:00');

    expect($minute->laeq_valid_ms)->toBe(3000)
        ->and($minute->ambiguous_ms)->toBe(2000)
        ->and($minute->quality_counts['ambiguous_overlap'])->toBe(4);
});

it('keeps estimated and calibrated series in separate streams', function (): void {
    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($this->fixture->records(5, $this->start)))->assertCreated();

    $estimated = app(ProvenanceRecords::class);
    $profile = $estimated->createProfile($this->fixture->device, [
        'channel' => 'mic-1', 'microphone_model' => 'Dayton UMM-6', 'sample_rate_hz' => 48000,
        'weighting_implementation_version' => 'a', 'filter_implementation_version' => 'f',
        'calibration_state' => CalibrationState::Estimated, 'supported_metrics' => ['laeq_db', 'lafmax_db', 'lceq_db', 'lcpeak_db', 'low_frequency_leq_db', 'rms_dbfs'],
        'agent_processing_version' => 'agent-0.1.0',
    ], $this->fixture->owner);
    $calibration = $estimated->createCalibration($this->fixture->device, [
        'channel' => 'mic-1', 'calibration_state' => CalibrationState::Estimated, 'reference_method' => 'phone app comparison',
    ], $this->fixture->owner);

    $records = $this->fixture->records(5, $this->start->addSeconds(10), 100, fn (array $r) => [...$r, 'profile_id' => $profile->uuid, 'calibration_id' => $calibration->uuid, 'laeq_db' => 70.0]);
    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($records))->assertCreated();
    app(RebuildRollups::class)->processDirty();

    $minutes = MeasurementRollup::query()->where('resolution_seconds', 60)->with('stream')->get();

    expect($minutes)->toHaveCount(2)
        ->and($minutes->pluck('stream.calibration_state')->map->value->sort()->values()->all())->toBe(['calibrated', 'estimated'])
        ->and($minutes->every(fn ($m) => $m->laeq_valid_ms === 5000))->toBeTrue();
});

it('energy-averages compatible third-octave bands over time', function (): void {
    $fixture = DeviceFixture::create(bands: [63, 80]);
    $records = [
        $fixture->record(1, $this->start, ['bands' => [['center_hz' => 63, 'level_db' => 40.0, 'weighting' => 'Z'], ['center_hz' => 80, 'level_db' => 30.0, 'weighting' => 'Z']]]),
        $fixture->record(2, $this->start->addSecond(), ['bands' => [['center_hz' => 63, 'level_db' => 60.0, 'weighting' => 'Z']]]),
    ];

    $this->devicePost($fixture, 'measurements/batches', $fixture->batch($records))->assertCreated();
    app(RebuildRollups::class)->processDirty();

    $bands = collect(rollup(60, '2026-10-08 12:15:00')->bands)->keyBy('center_hz');

    expect(Decibels::leq($bands[63]['energy_sum'], $bands[63]['valid_ms']))->toEqualWithDelta(57.0329, 0.0001)
        ->and($bands[80]['valid_ms'])->toBe(1000);

    $bad = $fixture->record(3, $this->start->addSeconds(2), ['bands' => [['center_hz' => 100, 'level_db' => 40.0, 'weighting' => 'Z']]]);
    $this->devicePost($fixture, 'measurements/batches', $fixture->batch([$bad]))->assertStatus(422);
});

it('does not clear a dirty marker created while a rebuild was running', function (): void {
    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($this->fixture->records(5, $this->start)))->assertCreated();

    $queue = app(MaintenanceQueue::class);
    $claimed = $queue->claim([MaintenanceJobKind::RollupMinute]);
    expect($claimed)->toHaveCount(1);

    // New data for the same bucket arrives while the claimed rebuild runs.
    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($this->fixture->records(5, $this->start->addSeconds(5), 6)))->assertCreated();

    $queue->complete($claimed[0]['id'], $claimed[0]['generation']);

    expect(DB::table('maintenance_jobs')->where('kind', 'rollup_minute')->where('status', 'pending')->count())->toBe(1);

    app(RebuildRollups::class)->processDirty();
    expect(rollup(60, '2026-10-08 12:15:00')->laeq_valid_ms)->toBe(10000);
});
