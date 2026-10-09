<?php

use App\Models\DeviceConfigAcknowledgment;
use App\Models\DeviceHeartbeat;
use App\Services\Devices\DeviceConfigurationService;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\DeviceFixture;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-08T12:20:00Z');
    $this->fixture = DeviceFixture::create();
    $this->configurations = app(DeviceConfigurationService::class);
});

function heartbeat(array $overrides = []): array
{
    return array_replace_recursive([
        'schema_version' => 1,
        'sent_at' => '2026-10-08T12:19:58.000Z',
        'agent_version' => 'agent-0.1.0',
        'boot_id' => '3f1c7a52-6a55-4f39-9b1f-0d7bb6a8f001',
        'uptime_seconds' => 3600,
        'capabilities' => [
            'channels' => ['mic-1'],
            'metrics' => ['laeq_db', 'lafmax_db', 'lceq_db', 'lcpeak_db', 'low_frequency_leq_db', 'rms_dbfs'],
            'third_octave_bands' => false,
            'recording_formats' => ['audio/wav', 'audio/flac'],
        ],
        'microphone_state' => 'ok',
        'free_disk_bytes' => 10_000_000_000,
        'total_disk_bytes' => 30_000_000_000,
        'queued_measurement_count' => 0,
        'pending_audio_bytes' => 0,
        'pending_audio_count' => 0,
        'oldest_pending_capture_at' => null,
        'desired_config_revision' => 1,
        'applied_config_revision' => null,
        'clock' => ['sync_state' => 'synchronized', 'offset_ms' => 3],
        'recent_dropped_intervals' => 0,
        'last_capture_error' => null,
    ], $overrides);
}

it('returns the complete configuration document with its hash and no provenance', function (): void {
    $this->deviceGet($this->fixture, 'configuration')
        ->assertOk()
        ->assertHeader('ETag', '"'.$this->fixture->configuration->content_hash.'"')
        ->assertJson([
            'revision' => 1,
            'sha256' => $this->fixture->configuration->content_hash,
            'applied_revision' => null,
            'configuration' => [
                'revision' => 1,
                'reporting_interval_seconds' => 30,
                'heartbeat_interval_seconds' => 60,
                'recording' => ['pre_roll_seconds' => 10, 'post_roll_seconds' => 30],
                'channels' => [['channel' => 'mic-1', 'enabled' => true, 'bands_enabled' => false]],
            ],
        ])
        ->assertJsonMissingPath('provenance')
        ->assertJsonMissingPath('configuration.channels.0.measurement_profile_id')
        ->assertJsonMissingPath('configuration.channels.0.deployment_id')
        ->assertJsonMissingPath('configuration.channels.0.calibration_id');
});

it('reports that no configuration is published yet', function (): void {
    $fixture = DeviceFixture::create();
    $fixture->device->configurations()->delete();

    $this->deviceGet($fixture, 'configuration')->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

it('keeps a revision pending until acknowledged, records rejections, and keeps history', function (): void {
    $this->devicePost($this->fixture, 'heartbeat', heartbeat())->assertOk()->assertJson(['configuration_pending' => true, 'desired_config_revision' => 1]);

    $this->devicePost($this->fixture, 'configuration/acknowledgments', ['schema_version' => 1, 'revision' => 1, 'status' => 'applied', 'applied_at' => '2026-10-08T12:20:00.000Z'])
        ->assertCreated()->assertJson(['applied_config_revision' => 1, 'recorded' => true]);
    // Duplicate ack is idempotent.
    $this->devicePost($this->fixture, 'configuration/acknowledgments', ['schema_version' => 1, 'revision' => 1, 'status' => 'applied', 'applied_at' => '2026-10-08T12:20:00.000Z'])
        ->assertOk()->assertJson(['recorded' => false]);

    $settings = $this->configurations->settingsFromDocument($this->fixture->configuration->document);
    $settings['reporting_interval_seconds'] = 60;
    $revision2 = $this->configurations->publish($this->fixture->device, $settings, $this->fixture->owner);

    $device = $this->fixture->device->fresh();
    expect($device->desired_config_revision)->toBe(2)->and($device->applied_config_revision)->toBe(1);

    $this->devicePost($this->fixture, 'configuration/acknowledgments', ['schema_version' => 1, 'revision' => 2, 'status' => 'rejected', 'reason' => 'interval unsupported'])
        ->assertCreated()->assertJson(['applied_config_revision' => 1, 'desired_config_revision' => 2]);

    $rollback = $this->configurations->rollback($this->fixture->device, $this->fixture->configuration, $this->fixture->owner);
    expect($rollback->revision)->toBe(3)
        ->and($rollback->rollback_of_revision)->toBe(1)
        ->and($rollback->document['reporting_interval_seconds'])->toBe(30)
        ->and(collect($rollback->document)->except('revision')->all())->toBe(collect($this->fixture->configuration->document)->except('revision')->all())
        ->and($revision2->fresh()->document['reporting_interval_seconds'])->toBe(60)
        ->and(DeviceConfigAcknowledgment::query()->where('status', 'rejected')->sole()->reason)->toBe('interval unsupported');

    $this->deviceGet($this->fixture, 'configuration')->assertJson(['revision' => 3]);

    $this->devicePost($this->fixture, 'configuration/acknowledgments', ['schema_version' => 1, 'revision' => 99, 'status' => 'applied'])->assertNotFound();
    $this->devicePost($this->fixture, 'configuration/acknowledgments', ['schema_version' => 1, 'revision' => 3, 'status' => 'rejected'])->assertStatus(422);
});

it('records heartbeat telemetry separately from receipt time', function (): void {
    $this->devicePost($this->fixture, 'heartbeat', heartbeat(['clock' => ['sync_state' => 'unsynchronized', 'offset_ms' => 4200]]))->assertOk();

    $heartbeat = DeviceHeartbeat::query()->sole();
    $device = $this->fixture->device->fresh();
    expect($heartbeat->sent_at->toIso8601ZuluString())->toBe('2026-10-08T12:19:58Z')
        ->and($heartbeat->received_at->toIso8601ZuluString())->toBe('2026-10-08T12:20:00Z')
        ->and($heartbeat->hasClockProblem())->toBeTrue()
        ->and($device->latest_heartbeat_id)->toBe($heartbeat->id)
        ->and($device->capabilities['channels'])->toBe(['mic-1'])
        ->and($device->software_version)->toBe('agent-0.1.0');

    $this->devicePost($this->fixture, 'heartbeat', heartbeat(['microphone_state' => 'melted']))->assertStatus(422);
    $this->devicePost($this->fixture, 'heartbeat', heartbeat(['extra' => 1]))->assertStatus(422);
});

it('validates published configuration against reported capabilities', function (): void {
    $this->devicePost($this->fixture, 'heartbeat', heartbeat(['capabilities' => ['metrics' => ['rms_dbfs', 'laeq_db']]]))->assertOk();

    $settings = $this->configurations->settingsFromDocument($this->fixture->configuration->document);

    expect(fn () => $this->configurations->publish($this->fixture->device->fresh(), $settings, $this->fixture->owner))
        ->toThrow(ValidationException::class);
    expect($this->fixture->device->configurations()->count())->toBe(1);
});

it('serves a configuration whose sha256 is reproducible from the served document', function (): void {
    $settings = $this->configurations->settingsFromDocument($this->fixture->configuration->document);
    $settings['relative_enabled'] = true;
    $settings['relative_delta_db'] = 15;
    $settings['absolute_enabled'] = true;
    $settings['absolute_level_db'] = 85;
    $this->configurations->publish($this->fixture->device, $settings, $this->fixture->owner);

    $served = json_decode($this->deviceGet($this->fixture, 'configuration')->assertOk()->getContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($served['configuration']['detection']['baseline_relative']['delta_db'])->toBe(15)
        ->and(CanonicalJson::hash($served['configuration']))->toBe($served['sha256']);
});

it('republishes a revision written before channels dropped their provenance references', function (): void {
    $legacy = $this->fixture->configuration->document;
    $legacy['revision'] = 2;
    $legacy['channels'][0] += [
        'measurement_profile_id' => $this->fixture->profile->uuid,
        'deployment_id' => $this->fixture->deployment->uuid,
        'calibration_id' => $this->fixture->calibration->uuid,
        'calibration_state' => 'calibrated',
    ];
    $this->fixture->device->configurations()->create([
        'account_id' => $this->fixture->account->id,
        'revision' => 2,
        'document' => $legacy,
        'content_hash' => CanonicalJson::hash($legacy),
        'issued_at' => CarbonImmutable::now(),
    ]);

    $settings = $this->configurations->settingsFromDocument($legacy);
    $published = $this->configurations->publish($this->fixture->device, $settings, $this->fixture->owner);

    expect($settings['channels'])->toBe([['channel' => 'mic-1', 'enabled' => true, 'metrics' => $legacy['channels'][0]['metrics'], 'bands_enabled' => false]])
        ->and($published->revision)->toBe(3)
        ->and($published->document['channels'][0])->toBe($settings['channels'][0]);
});

it('accepts a heartbeat without an acquisition session', function (): void {
    $payload = heartbeat(['microphone_state' => 'disconnected']);
    unset($payload['boot_id']);

    $this->devicePost($this->fixture, 'heartbeat', $payload)->assertOk();
    $this->devicePost($this->fixture, 'heartbeat', heartbeat(['boot_id' => null]))->assertOk();

    expect(DeviceHeartbeat::query()->count())->toBe(2)
        ->and(DeviceHeartbeat::query()->latest('id')->first()->boot_id)->toBeNull()
        ->and($this->fixture->device->fresh()->current_boot_id)->toBeNull();
});
