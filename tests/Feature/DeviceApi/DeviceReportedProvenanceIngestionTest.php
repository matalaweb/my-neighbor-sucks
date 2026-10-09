<?php

use App\Enums\CalibrationState;
use App\Enums\ExportKind;
use App\Enums\ProvenanceSource;
use App\Filament\Resources\NoiseEvents\NoiseEventResource;
use App\Models\Measurement;
use App\Models\MeasurementStream;
use App\Models\NoiseEvent;
use App\Services\Devices\ProvenanceRecords;
use App\Services\Events\SnapshotEventMeasurements;
use App\Services\Exports\BuildEvidenceExport;
use App\Services\Exports\RequestEvidenceExport;
use App\Services\Measurements\RebuildRollups;
use App\Services\Storage\EvidenceStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DeviceFixture;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-08T12:20:00Z');
    $this->start = CarbonImmutable::parse('2026-10-08T12:15:00Z');
});

/**
 * Placement revision of the stream each reading was stored under, by sequence.
 *
 * @return array<int, int|null>
 */
function placementRevisionsBySequence(): array
{
    return Measurement::query()->with('stream.deployment')->orderBy('sequence')->get()
        ->mapWithKeys(fn (Measurement $measurement): array => [$measurement->sequence => $measurement->stream->deployment?->revision])
        ->all();
}

it('ingests readings and events from device-registered records on local defaults', function (): void {
    $fixture = DeviceFixture::create();
    $records = $fixture->records(10, $this->start, tweak: fn (array $record): array => [...$record, 'configuration_revision' => null]);
    unset($records[0]['configuration_revision']);

    $this->devicePost($fixture, 'measurements/batches', $fixture->batch($records))->assertCreated()->assertJson(['inserted_count' => 10]);
    $this->devicePost($fixture, 'events', $fixture->event((string) Str::uuid(), 1, $this->start->addSeconds(2), $this->start->addSeconds(6), ['configuration_revision' => null]))
        ->assertCreated();
    app(RebuildRollups::class)->processDirty();

    $stream = MeasurementStream::query()->sole();

    expect($fixture->profile->source)->toBe(ProvenanceSource::Device)
        ->and(Measurement::query()->whereNull('configuration_revision')->count())->toBe(10)
        ->and(NoiseEvent::query()->sole()->configuration_revision)->toBeNull()
        ->and(NoiseEvent::query()->sole()->stream_id)->toBe($stream->id)
        ->and($stream->measurement_profile_id)->toBe($fixture->profile->id)
        ->and($stream->device_calibration_id)->toBe($fixture->calibration->id)
        ->and($stream->device_deployment_id)->toBe($fixture->deployment->id)
        ->and(DB::table('measurement_rollups')->where('resolution_seconds', 60)->value('configuration_revisions'))->toBe('[null]');
});

it('still rejects a configuration revision the device does not have', function (): void {
    $fixture = DeviceFixture::create();

    $this->devicePost($fixture, 'measurements/batches', $fixture->batch([$fixture->record(1, $this->start, ['configuration_revision' => 7])]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'unknown_provenance')
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['records.0.configuration_revision']]]]);
});

it('assigns each reading the placement in effect at its capture time', function (): void {
    $fixture = DeviceFixture::create(placementSince: '2026-10-08T12:15:05Z');
    app(ProvenanceRecords::class)->createDeployment($fixture->device, ['room' => 'Garage', 'location_type' => 'outdoor', 'effective_at' => '2026-10-08T12:15:08Z'], $fixture->owner);

    $this->devicePost($fixture, 'measurements/batches', $fixture->batch($fixture->records(10, $this->start)))->assertCreated();

    expect(placementRevisionsBySequence())->toBe([1 => null, 2 => null, 3 => null, 4 => null, 5 => null, 6 => 1, 7 => 1, 8 => 1, 9 => 2, 10 => 2])
        ->and(MeasurementStream::query()->count())->toBe(3)
        ->and(MeasurementStream::query()->whereNull('device_deployment_id')->sole()->label())->toContain('placement not recorded');
});

it('stores readings without a placement when the owner has recorded none', function (): void {
    $fixture = DeviceFixture::create(placementSince: null);

    $this->devicePost($fixture, 'measurements/batches', $fixture->batch($fixture->records(3, $this->start)))->assertCreated();
    $this->devicePost($fixture, 'events', $fixture->event((string) Str::uuid(), 1, $this->start, $this->start->addSeconds(2)))->assertCreated();

    expect(placementRevisionsBySequence())->toBe([1 => null, 2 => null, 3 => null])
        ->and(NoiseEvent::query()->sole()->stream->device_deployment_id)->toBeNull();
});

it('accepts and ignores a deployment_id from older agents', function (): void {
    $fixture = DeviceFixture::create();
    $legacy = fn (array $record): array => [...$record, 'deployment_id' => (string) Str::uuid()];
    $batch = $fixture->batch($fixture->records(3, $this->start, tweak: $legacy));

    $this->devicePost($fixture, 'measurements/batches', $batch)->assertCreated()->assertJson(['inserted_count' => 3]);
    $this->devicePost($fixture, 'measurements/batches', $batch)->assertOk()->assertJson(['replayed' => true]);
    $this->devicePost($fixture, 'events', $fixture->event((string) Str::uuid(), 1, $this->start, null, ['deployment_id' => (string) Str::uuid()]))->assertCreated();

    expect(MeasurementStream::query()->sole()->device_deployment_id)->toBe($fixture->deployment->id);
});

it('keeps owner-created legacy profiles and calibrations valid for ingestion', function (): void {
    $fixture = DeviceFixture::create();
    $provenance = app(ProvenanceRecords::class);
    $profile = $provenance->createProfile($fixture->device, [
        'channel' => 'mic-1', 'microphone_model' => 'Legacy mic', 'sample_rate_hz' => 48000, 'weighting_implementation_version' => 'w1',
        'filter_implementation_version' => 'f1', 'calibration_state' => CalibrationState::Calibrated, 'supported_metrics' => ['laeq_db', 'rms_dbfs'],
        'agent_processing_version' => 'agent-0.0.9',
    ], $fixture->owner);
    $calibration = $provenance->createCalibration($fixture->device, ['channel' => 'mic-1', 'calibration_state' => 'calibrated', 'reference_method' => '94 dB calibrator'], $fixture->owner);

    $record = $fixture->record(1, $this->start, ['profile_id' => $profile->uuid, 'calibration_id' => $calibration->uuid, 'lafmax_db' => null, 'lceq_db' => null, 'lcpeak_db' => null, 'low_frequency_leq_db' => null]);

    $this->devicePost($fixture, 'measurements/batches', $fixture->batch([$record]))->assertCreated();

    expect($profile->fresh()->source)->toBe(ProvenanceSource::Owner)
        ->and(MeasurementStream::query()->sole()->measurement_profile_id)->toBe($profile->id);
});

it('asks the agent to register an unknown profile before resubmitting', function (): void {
    $fixture = DeviceFixture::create();

    $this->devicePost($fixture, 'measurements/batches', $fixture->batch([$fixture->record(1, $this->start, ['profile_id' => (string) Str::uuid()])]))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'unknown_provenance')
        ->assertJsonPath('error.retry', 'after_configuration_refresh');

    expect(Measurement::query()->count())->toBe(0);
});

it('shows placement not recorded and local defaults on the event page and in exports', function (): void {
    $fixture = DeviceFixture::create(placementSince: null);
    $this->devicePost($fixture, 'measurements/batches', $fixture->batch($fixture->records(20, $this->start, tweak: fn (array $record): array => [...$record, 'configuration_revision' => null])))->assertCreated();
    $eventId = (string) Str::uuid();
    $this->devicePost($fixture, 'events', $fixture->event($eventId, 1, $this->start->addSeconds(5), $this->start->addSeconds(10), ['configuration_revision' => null]))->assertCreated();
    app(RebuildRollups::class)->processDirty();
    app(SnapshotEventMeasurements::class)->processDirty();
    $event = NoiseEvent::query()->where('uuid', $eventId)->sole();

    $this->actingAs($fixture->owner)
        ->get(NoiseEventResource::getUrl('view', ['record' => $event, 'tenant' => $fixture->account]))
        ->assertOk()
        ->assertSee('Placement not recorded')
        ->assertSee('Local defaults (no configuration published yet)');

    $export = app(RequestEvidenceExport::class)->handle($fixture->owner, $fixture->property, ExportKind::CsvMeasurements, [
        'type' => 'range', 'from_date' => '2026-10-08', 'to_date' => '2026-10-08',
    ]);
    $csv = app(EvidenceStorage::class)->disk()->get(app(BuildEvidenceExport::class)->build($export)->object_key);

    expect(explode("\n", $csv)[1])->toContain(',mic-1,')->toContain(','.$fixture->profile->uuid.',')->toContain(','.BuildEvidenceExport::LOCAL_DEFAULTS.',');
});
