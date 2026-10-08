<?php

use App\Enums\CalibrationState;
use App\Models\Measurement;
use App\Models\MeasurementBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeviceFixture;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-08T12:20:00Z');
    $this->fixture = DeviceFixture::create();
    $this->start = CarbonImmutable::parse('2026-10-08T12:15:00Z');
});

it('ingests a batch atomically and reports counts only after commit', function (): void {
    $payload = $this->fixture->batch($this->fixture->records(30, $this->start));

    $response = $this->devicePost($this->fixture, 'measurements/batches', $payload);

    $response->assertCreated()
        ->assertJsonStructure(['request_id', 'server_received_at', 'batch_id', 'inserted_count', 'duplicate_count', 'accepted_interval' => ['start', 'end']])
        ->assertJson([
            'batch_id' => $payload['batch_id'],
            'record_count' => 30,
            'inserted_count' => 30,
            'duplicate_count' => 0,
            'replayed' => false,
            'accepted_interval' => ['start' => '2026-10-08T12:15:00.000Z', 'end' => '2026-10-08T12:15:30.000Z'],
        ]);

    expect(Measurement::count())->toBe(30)
        ->and(MeasurementBatch::count())->toBe(1)
        ->and(DB::table('maintenance_jobs')->where('kind', 'rollup_minute')->count())->toBe(1)
        ->and($this->fixture->device->fresh()->latest_capture_at->toIso8601ZuluString())->toBe('2026-10-08T12:15:29Z');
});

it('returns the original result for an exact retry without inserting again', function (): void {
    $payload = $this->fixture->batch($this->fixture->records(10, $this->start));

    $first = $this->devicePost($this->fixture, 'measurements/batches', $payload)->assertCreated();
    $payload['sent_at'] = '2026-10-08T12:19:00.000Z';
    $second = $this->devicePost($this->fixture, 'measurements/batches', $payload);

    $second->assertOk()->assertJson([
        'replayed' => true,
        'inserted_count' => 10,
        'original_request_id' => $first->json('request_id'),
    ]);
    expect($second->json('request_id'))->not->toBe($first->json('request_id'))
        ->and(Measurement::count())->toBe(10);
});

it('rejects a changed payload under the same batch id with 409', function (): void {
    $payload = $this->fixture->batch($this->fixture->records(5, $this->start));
    $this->devicePost($this->fixture, 'measurements/batches', $payload)->assertCreated();

    $payload['records'][2]['laeq_db'] = 61.3;

    $this->devicePost($this->fixture, 'measurements/batches', $payload)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'batch_conflict')
        ->assertJsonPath('error.permanent', true);

    expect(Measurement::query()->where('sequence', 3)->value('laeq_db'))->toBe(45.0);
});

it('deduplicates overlapping differently assembled batches and conflicts on changed rows', function (): void {
    $records = $this->fixture->records(20, $this->start);

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch(array_slice($records, 0, 12)))->assertCreated();

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch(array_slice($records, 8, 12)))
        ->assertCreated()
        ->assertJson(['inserted_count' => 8, 'duplicate_count' => 4]);

    expect(Measurement::count())->toBe(20);

    $changed = array_slice($records, 15, 5);
    $changed[] = $this->fixture->record(21, $this->start->addSeconds(20));
    $changed[0]['lafmax_db'] = 99.9;

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($changed))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'measurement_conflict')
        ->assertJsonPath('error.details.conflicts.0.sequence', 16);

    expect(Measurement::count())->toBe(20)
        ->and(Measurement::query()->where('sequence', 21)->exists())->toBeFalse();
});

it('treats identical repeats inside one batch as duplicates and changed repeats as conflicts', function (): void {
    $records = $this->fixture->records(3, $this->start);
    $records[] = $records[1];

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($records))
        ->assertCreated()->assertJson(['inserted_count' => 3, 'duplicate_count' => 1]);

    $more = $this->fixture->records(2, $this->start->addSeconds(10), 11);
    $more[] = [...$more[0], 'laeq_db' => 70.0];

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($more))
        ->assertStatus(409)->assertJsonPath('error.code', 'measurement_conflict');
});

it('rolls back an invalid batch completely', function (): void {
    $records = $this->fixture->records(10, $this->start);
    $records[7]['captured_at'] = '2026-10-08T12:15:07.500Z';

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($records))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.errors', fn (array $errors): bool => array_key_exists('records.7.captured_at', $errors));

    expect(Measurement::count())->toBe(0)->and(MeasurementBatch::count())->toBe(0);
});

it('rejects unknown fields, non-finite numbers, and wrong types', function (array $override, string $path): void {
    $record = array_merge($this->fixture->record(1, $this->start), $override);

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch([$record]))
        ->assertStatus(422)
        ->assertJsonPath('error.details.errors', fn (array $errors): bool => array_key_exists($path, $errors));
})->with([
    'unknown field' => [['peak_db' => 90.0], 'records.0.peak_db'],
    'string metric' => [['laeq_db' => '45'], 'records.0.laeq_db'],
    'out of range' => [['laeq_db' => 999.0], 'records.0.laeq_db'],
    'bad flag' => [['quality_flags' => ['loud']], 'records.0.quality_flags.0'],
    'duration' => [['duration_ms' => 500], 'records.0.duration_ms'],
    'sequence' => [['sequence' => -1], 'records.0.sequence'],
]);

it('rejects overflowing numbers that decode to infinity', function (): void {
    $payload = $this->fixture->batch([$this->fixture->record(1, $this->start)]);
    $json = str_replace('"laeq_db":45.0', '"laeq_db":1e400', json_encode($payload, JSON_PRESERVE_ZERO_FRACTION));

    $response = $this->call('POST', '/api/v1/device/measurements/batches', server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.$this->fixture->token,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], content: $json);

    $response->assertStatus(422)->assertJsonPath('error.details.errors', fn (array $errors): bool => array_key_exists('records.0.laeq_db', $errors));
});

it('rejects absolute SPL values from an uncalibrated profile', function (): void {
    $fixture = DeviceFixture::create(CalibrationState::Uncalibrated);
    $record = $fixture->record(1, $this->start, ['laeq_db' => 50.0]);

    $this->devicePost($fixture, 'measurements/batches', $fixture->batch([$record]))
        ->assertStatus(422)
        ->assertJsonPath('error.details.errors', fn (array $errors): bool => array_key_exists('records.0.laeq_db', $errors));

    $this->devicePost($fixture, 'measurements/batches', $fixture->batch([$fixture->record(1, $this->start)]))
        ->assertCreated();
});

it('rejects provenance that is not provisioned for the device', function (): void {
    $other = DeviceFixture::create();
    $record = $this->fixture->record(1, $this->start, ['profile_id' => $other->profile->uuid]);

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch([$record]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'unknown_provenance')
        ->assertJsonPath('error.retry', 'after_configuration_refresh');
});

it('rejects timestamps more than five minutes in the future with a clock error', function (): void {
    $records = [$this->fixture->record(1, CarbonImmutable::now()->addMinutes(6))];

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($records))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'clock_future_timestamp')
        ->assertJsonPath('error.retry', 'after_clock_sync');
});

it('enforces the backfill window unless an owner enables an import window', function (): void {
    $old = CarbonImmutable::now()->subDays(31)->startOfMinute();
    $payload = $this->fixture->batch([$this->fixture->record(1, $old)]);

    $this->devicePost($this->fixture, 'measurements/batches', $payload)
        ->assertStatus(422)->assertJsonPath('error.code', 'outside_backfill_window');

    $this->fixture->device->forceFill([
        'import_window_starts_at' => $old->subDay(),
        'import_window_expires_at' => CarbonImmutable::now()->addDay(),
    ])->save();

    $this->devicePost($this->fixture, 'measurements/batches', $payload)->assertCreated();
});

it('limits batch size, request bytes, and decompressed gzip bytes', function (): void {
    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($this->fixture->records(301, $this->start->subMinutes(10))))
        ->assertStatus(413)->assertJsonPath('error.code', 'payload_too_large');

    $bomb = gzencode(str_repeat(' ', 2 * 1024 * 1024));
    $this->call('POST', '/api/v1/device/measurements/batches', server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.$this->fixture->token,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_CONTENT_ENCODING' => 'gzip',
        'HTTP_ACCEPT' => 'application/json',
    ], content: $bomb)->assertStatus(413);

    $this->call('POST', '/api/v1/device/measurements/batches', server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.$this->fixture->token,
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => (string) (2 * 1024 * 1024),
        'HTTP_ACCEPT' => 'application/json',
    ], content: '{}')->assertStatus(413);
});

it('accepts gzip-compressed batches', function (): void {
    $payload = $this->fixture->batch($this->fixture->records(5, $this->start));

    $this->call('POST', '/api/v1/device/measurements/batches', server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.$this->fixture->token,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_CONTENT_ENCODING' => 'gzip',
        'HTTP_ACCEPT' => 'application/json',
    ], content: gzencode(json_encode($payload)))->assertCreated()->assertJson(['inserted_count' => 5]);
});

it('stores unsupported metrics as null with reasons, never zero', function (): void {
    $record = $this->fixture->record(1, $this->start, ['lcpeak_db' => null, 'null_reasons' => ['lcpeak_db' => 'unreliable']]);

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch([$record]))->assertCreated();

    $row = Measurement::query()->first();
    expect($row->lcpeak_db)->toBeNull()->and($row->null_reasons)->toBe(['lcpeak_db' => 'unreliable']);

    $missingReason = $this->fixture->record(2, $this->start->addSecond(), ['lcpeak_db' => null]);
    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch([$missingReason]))->assertStatus(422);
});

it('preserves reported values and quality flags exactly', function (): void {
    $record = $this->fixture->record(1, $this->start, ['laeq_db' => 78.43219, 'quality_flags' => ['clipping', 'audio_dropout']]);

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch([$record]))->assertCreated();

    $row = Measurement::query()->first();
    expect($row->laeq_db)->toBe(78.43219)->and($row->qualityFlagValues())->toBe(['clipping', 'audio_dropout']);
});

it('every device response carries request_id and server_received_at', function (): void {
    $this->withHeaders(['Authorization' => 'Bearer nmd_invalid'])->postJson('/api/v1/device/heartbeat', [])
        ->assertStatus(401)
        ->assertJsonStructure(['request_id', 'server_received_at', 'error' => ['code', 'message', 'retry']])
        ->assertJsonPath('error.code', 'invalid_credentials');
});
