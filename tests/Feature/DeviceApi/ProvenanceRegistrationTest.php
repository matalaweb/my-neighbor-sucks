<?php

use App\Enums\CalibrationState;
use App\Enums\ProvenanceSource;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\DeviceCalibration;
use App\Models\MeasurementProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\DeviceFixture;

beforeEach(function (): void {
    Storage::fake('s3');
    CarbonImmutable::setTestNow('2026-10-09T15:00:00Z');
    $this->fixture = DeviceFixture::create();
    $this->curve = "\"Sens Factor =-0.082dB, AGain =18dB, SERNO: 7213485\"\n10.054\t-1.70\n1000.000\t0.00\n";
    $this->profileId = (string) Str::uuid7();
    $this->calibrationId = (string) Str::uuid7();
});

/**
 * @param  array<string, mixed>  $calibrationOverrides
 * @return array<string, mixed>
 */
function provenanceRegistration(string $profileId, string $calibrationId, string $curve, array $calibrationOverrides = []): array
{
    $attachment = [
        'purpose' => 'frequency_response',
        'filename' => '7213485_90deg.txt',
        'media_type' => 'text/plain',
        'sha256' => hash('sha256', $curve),
        'content_base64' => base64_encode($curve),
    ];

    return [
        'schema_version' => 1,
        'sent_at' => '2026-10-09T15:00:00.000Z',
        'measurement_profiles' => [[...DeviceFixture::profilePayload($profileId, CalibrationState::Estimated), 'microphone_model' => 'miniDSP UMIK-1']],
        'calibrations' => [[...DeviceFixture::calibrationPayload($calibrationId, CalibrationState::Estimated, [$attachment]), ...$calibrationOverrides]],
    ];
}

it('registers device-sourced profiles and calibrations with the next per-channel revision', function (): void {
    $response = $this->devicePost($this->fixture, 'provenance', provenanceRegistration($this->profileId, $this->calibrationId, $this->curve));

    $response->assertOk()
        ->assertJsonStructure(['request_id', 'server_received_at'])
        ->assertJson([
            'measurement_profiles' => [['id' => $this->profileId, 'revision' => 2, 'status' => 'created']],
            'calibrations' => [['id' => $this->calibrationId, 'revision' => 2, 'status' => 'created']],
        ]);

    $profile = MeasurementProfile::query()->where('uuid', $this->profileId)->sole();
    $calibration = DeviceCalibration::query()->where('uuid', $this->calibrationId)->sole();

    expect($profile->device_id)->toBe($this->fixture->device->id)
        ->and($profile->source)->toBe(ProvenanceSource::Device)
        ->and($profile->created_by)->toBeNull()
        ->and($profile->microphone_model)->toBe('miniDSP UMIK-1')
        ->and($profile->calibration_state)->toBe(CalibrationState::Estimated)
        ->and($calibration->source)->toBe(ProvenanceSource::Device)
        ->and((float) $calibration->sensitivity_dbfs_at_94db)->toBe(-30.082)
        ->and(AuditLog::query()->where('action', 'device.profile.registered')->where('entity_uuid', $this->profileId)->sole()->device_id)->toBe($this->fixture->device->id)
        ->and(AuditLog::query()->where('action', 'device.calibration.registered')->where('entity_uuid', $this->calibrationId)->exists())->toBeTrue();
});

it('stores calibration files inline like owner uploads, with their checksum', function (): void {
    $this->devicePost($this->fixture, 'provenance', provenanceRegistration($this->profileId, $this->calibrationId, $this->curve))->assertOk();

    $attachment = Attachment::query()->sole();

    expect($attachment->attachable_id)->toBe(DeviceCalibration::query()->where('uuid', $this->calibrationId)->value('id'))
        ->and($attachment->purpose)->toBe('frequency_response')
        ->and($attachment->original_filename)->toBe('7213485_90deg.txt')
        ->and($attachment->mime_type)->toBe('text/plain')
        ->and($attachment->sha256)->toBe(hash('sha256', $this->curve))
        ->and((int) $attachment->byte_size)->toBe(strlen($this->curve))
        ->and($attachment->uploaded_by)->toBeNull()
        ->and($attachment->disk)->toBe('s3')
        ->and(Storage::disk('s3')->get($attachment->object_key))->toBe($this->curve);
});

it('treats a repeated registration with identical content as a no-op', function (): void {
    $payload = provenanceRegistration($this->profileId, $this->calibrationId, $this->curve);
    $this->devicePost($this->fixture, 'provenance', $payload)->assertOk();

    // Same records with a new send time and different number spellings: still identical content.
    $payload['sent_at'] = '2026-10-09T15:05:00.000Z';
    $payload['measurement_profiles'][0]['low_frequency_lower_hz'] = 20.0;
    $payload['calibrations'][0]['reference_level_db'] = 94.0;

    $this->devicePost($this->fixture, 'provenance', $payload)
        ->assertOk()
        ->assertJson([
            'measurement_profiles' => [['id' => $this->profileId, 'revision' => 2, 'status' => 'existing']],
            'calibrations' => [['id' => $this->calibrationId, 'revision' => 2, 'status' => 'existing']],
        ]);

    expect(MeasurementProfile::query()->where('uuid', $this->profileId)->count())->toBe(1)
        ->and(Attachment::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'device.profile.registered')->count())->toBe(2);
});

it('rejects changed content under a registered id and stores nothing from the request', function (): void {
    $this->devicePost($this->fixture, 'provenance', provenanceRegistration($this->profileId, $this->calibrationId, $this->curve))->assertOk();
    $newProfileId = (string) Str::uuid7();
    $changed = provenanceRegistration($this->profileId, $this->calibrationId, $this->curve, ['sensitivity_dbfs_at_94db' => -29.5]);
    $changed['measurement_profiles'][] = DeviceFixture::profilePayload($newProfileId, CalibrationState::Uncalibrated, channel: 'mic-2');

    $this->devicePost($this->fixture, 'provenance', $changed)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'provenance_conflict')
        ->assertJsonPath('error.retry', 'after_correction')
        ->assertJsonPath('error.permanent', true)
        ->assertJsonPath('error.details.conflicts.0', ['path' => 'calibrations.0.id', 'id' => $this->calibrationId, 'reason' => 'content_changed']);

    expect(MeasurementProfile::query()->where('uuid', $newProfileId)->exists())->toBeFalse()
        ->and((float) DeviceCalibration::query()->where('uuid', $this->calibrationId)->value('sensitivity_dbfs_at_94db'))->toBe(-30.082);
});

it('treats a changed calibration file as changed content', function (): void {
    $this->devicePost($this->fixture, 'provenance', provenanceRegistration($this->profileId, $this->calibrationId, $this->curve))->assertOk();

    $this->devicePost($this->fixture, 'provenance', provenanceRegistration($this->profileId, $this->calibrationId, $this->curve."20000.000\t-2.84\n"))
        ->assertStatus(409)
        ->assertJsonPath('error.details.conflicts.0.reason', 'content_changed');

    expect(Attachment::query()->count())->toBe(1);
});

it('rejects an id that another device registered', function (): void {
    $this->devicePost($this->fixture, 'provenance', provenanceRegistration($this->profileId, $this->calibrationId, $this->curve))->assertOk();
    $other = DeviceFixture::create();

    $this->devicePost($other, 'provenance', provenanceRegistration($this->profileId, (string) Str::uuid7(), $this->curve))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'provenance_conflict')
        ->assertJsonPath('error.details.conflicts.0.reason', 'registered_by_another_device');

    expect($other->device->measurementProfiles()->count())->toBe(1)
        ->and($other->device->calibrations()->count())->toBe(1);
});

it('rejects an attachment whose sha256 does not match the decoded bytes', function (): void {
    $payload = provenanceRegistration($this->profileId, $this->calibrationId, $this->curve);
    $payload['calibrations'][0]['attachments'][0]['sha256'] = hash('sha256', 'other bytes');

    $this->devicePost($this->fixture, 'provenance', $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['calibrations.0.attachments.0.sha256']]]]);

    expect(MeasurementProfile::query()->where('uuid', $this->profileId)->exists())->toBeFalse()
        ->and(Attachment::query()->count())->toBe(0);
});

it('rejects absolute SPL metrics on an uncalibrated profile', function (): void {
    $payload = [
        'schema_version' => 1,
        'measurement_profiles' => [DeviceFixture::profilePayload($this->profileId, CalibrationState::Uncalibrated, ['rms_dbfs', 'laeq_db'])],
    ];

    $this->devicePost($this->fixture, 'provenance', $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['measurement_profiles.0.supported_metrics']]]]);

    expect(MeasurementProfile::query()->where('uuid', $this->profileId)->exists())->toBeFalse();
});

it('validates registered values like the former owner forms', function (array $payload, string $errorPath): void {
    $this->devicePost($this->fixture, 'provenance', ['schema_version' => 1, ...$payload])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['errors' => [$errorPath]]]]);
})->with([
    'nothing to register' => [['measurement_profiles' => [], 'calibrations' => []], 'measurement_profiles'],
    'uncalibrated calibration' => [['calibrations' => [[...DeviceFixture::calibrationPayload('0199c0de-0000-7000-8000-000000000001'), 'calibration_state' => 'uncalibrated']]], 'calibrations.0.calibration_state'],
    'invalid channel' => [['measurement_profiles' => [DeviceFixture::profilePayload('0199c0de-0000-7000-8000-000000000002', channel: 'mic 1')]], 'measurement_profiles.0.channel'],
    'unknown metric' => [['measurement_profiles' => [DeviceFixture::profilePayload('0199c0de-0000-7000-8000-000000000003', metrics: ['laeq_db', 'la_max'])]], 'measurement_profiles.0.supported_metrics.1'],
    'non-positive sample rate' => [['measurement_profiles' => [[...DeviceFixture::profilePayload('0199c0de-0000-7000-8000-000000000004'), 'sample_rate_hz' => 0]]], 'measurement_profiles.0.sample_rate_hz'],
    'unknown field' => [['measurement_profiles' => [[...DeviceFixture::profilePayload('0199c0de-0000-7000-8000-000000000005'), 'deployment_id' => '0199c0de-0000-7000-8000-000000000006']]], 'measurement_profiles.0'],
    'too many records' => [['measurement_profiles' => array_map(fn (int $i): array => DeviceFixture::profilePayload(sprintf('0199c0de-0000-7000-8000-%012d', $i + 10)), range(1, 9))], 'measurement_profiles'],
]);

it('accepts calibration files beyond the general 1 MiB request limit', function (): void {
    $curve = str_repeat("1000.000\t0.00\n", 60_000);
    $payload = provenanceRegistration($this->profileId, $this->calibrationId, $curve);

    expect(strlen(json_encode($payload)))->toBeGreaterThan(1024 * 1024);

    $this->devicePost($this->fixture, 'provenance', $payload)->assertOk();

    expect((int) Attachment::query()->sole()->byte_size)->toBe(strlen($curve));
});

it('requires the measurements ability', function (): void {
    $this->fixture->device->credentials()->update(['abilities' => json_encode(['heartbeat:write'])]);

    $this->devicePost($this->fixture, 'provenance', provenanceRegistration($this->profileId, $this->calibrationId, $this->curve))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden_ability');
});
