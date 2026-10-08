<?php

namespace Tests\Support;

use App\Enums\CalibrationState;
use App\Enums\MembershipRole;
use App\Models\Account;
use App\Models\Device;
use App\Models\DeviceCalibration;
use App\Models\DeviceConfiguration;
use App\Models\DeviceDeployment;
use App\Models\MeasurementProfile;
use App\Models\Property;
use App\Models\User;
use App\Services\Devices\DeviceConfigurationService;
use App\Services\Devices\DeviceCredentialService;
use App\Services\Devices\ProvenanceRecords;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A fully provisioned account/property/device with provenance records,
 * a published configuration, and a device credential.
 */
final class DeviceFixture
{
    public string $bootId;

    public function __construct(
        public Account $account,
        public User $owner,
        public Property $property,
        public Device $device,
        public DeviceDeployment $deployment,
        public MeasurementProfile $profile,
        public ?DeviceCalibration $calibration,
        public DeviceConfiguration $configuration,
        public string $token,
    ) {
        $this->bootId = (string) Str::uuid();
    }

    public static function create(CalibrationState $state = CalibrationState::Calibrated, ?array $metrics = null, ?Account $account = null, array $bands = []): self
    {
        $account ??= Account::factory()->create();
        $owner = User::factory()->create();
        $account->users()->attach($owner, ['role' => MembershipRole::Owner->value]);
        $property = Property::factory()->for($account)->create();
        $device = Device::factory()->for($property)->create(['account_id' => $account->id]);

        $provenance = app(ProvenanceRecords::class);

        $deployment = $provenance->createDeployment($device, [
            'room' => 'Front bedroom',
            'location_type' => 'indoor',
            'placement_description' => 'Window sill facing driveway',
            'effective_at' => '2026-01-01T00:00:00Z',
        ], $owner);

        $metrics ??= $state === CalibrationState::Uncalibrated
            ? ['rms_dbfs']
            : ['laeq_db', 'lafmax_db', 'lceq_db', 'lcpeak_db', 'low_frequency_leq_db', 'rms_dbfs'];

        $profile = $provenance->createProfile($device, [
            'channel' => 'mic-1',
            'microphone_model' => 'Dayton UMM-6',
            'microphone_serial' => 'SN-123',
            'sample_rate_hz' => 48000,
            'gain_db' => 12,
            'weighting_implementation_version' => 'aweight-1.0',
            'filter_implementation_version' => 'filters-1.0',
            'calibration_state' => $state,
            'supported_metrics' => $metrics,
            'low_frequency_lower_hz' => 20,
            'low_frequency_upper_hz' => 125,
            'band_centers_hz' => $bands,
            'agent_processing_version' => 'agent-0.1.0',
        ], $owner);

        $calibration = $state === CalibrationState::Uncalibrated ? null : $provenance->createCalibration($device, [
            'channel' => 'mic-1',
            'calibration_state' => $state,
            'reference_method' => $state === CalibrationState::Calibrated ? '94 dB acoustic calibrator' : 'Comparison with phone app',
            'reference_level_db' => 94,
            'reference_frequency_hz' => 1000,
        ], $owner);

        $configurations = app(DeviceConfigurationService::class);
        $settings = $configurations->defaults();
        $settings['channels'] = [[
            'channel' => 'mic-1',
            'enabled' => true,
            'metrics' => $metrics,
            'bands_enabled' => $bands !== [],
            'measurement_profile_id' => $profile->uuid,
            'deployment_id' => $deployment->uuid,
            'calibration_id' => $calibration?->uuid,
        ]];
        $configuration = $configurations->publish($device, $settings, $owner);

        $token = app(DeviceCredentialService::class)->issue($device, $owner)['token'];

        return new self($account, $owner, $property, $device->fresh(), $deployment, $profile, $calibration, $configuration, $token);
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function record(int $sequence, CarbonImmutable $capturedAt, array $overrides = []): array
    {
        $absolute = $this->profile->calibration_state !== CalibrationState::Uncalibrated;

        return array_merge([
            'boot_id' => $this->bootId,
            'sequence' => $sequence,
            'channel' => 'mic-1',
            'captured_at' => $capturedAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'duration_ms' => 1000,
            'deployment_id' => $this->deployment->uuid,
            'profile_id' => $this->profile->uuid,
            'calibration_id' => $this->calibration?->uuid,
            'configuration_revision' => $this->configuration->revision,
            'laeq_db' => $absolute ? 45.0 : null,
            'lafmax_db' => $absolute ? 50.0 : null,
            'lceq_db' => $absolute ? 55.0 : null,
            'lcpeak_db' => $absolute ? 70.0 : null,
            'low_frequency_leq_db' => $absolute ? 52.0 : null,
            'rms_dbfs' => -40.0,
            'quality_flags' => [],
            'bands' => [],
        ], $overrides);
    }

    /**
     * Sequential records starting at $start (one per second).
     *
     * @return list<array<string, mixed>>
     */
    public function records(int $count, CarbonImmutable $start, int $firstSequence = 1, ?callable $tweak = null): array
    {
        $records = [];

        for ($i = 0; $i < $count; $i++) {
            $record = $this->record($firstSequence + $i, $start->addSeconds($i));
            $records[] = $tweak ? $tweak($record, $i) : $record;
        }

        return $records;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    public function batch(array $records, ?string $batchId = null): array
    {
        return [
            'schema_version' => 1,
            'batch_id' => $batchId ?? (string) Str::uuid(),
            'sent_at' => CarbonImmutable::now()->format('Y-m-d\TH:i:s.v\Z'),
            'records' => $records,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function event(string $eventId, int $revision, CarbonImmutable $startedAt, ?CarbonImmutable $endedAt, array $overrides = []): array
    {
        $absolute = $this->profile->calibration_state !== CalibrationState::Uncalibrated;

        return array_replace_recursive([
            'schema_version' => 1,
            'event_id' => $eventId,
            'revision' => $revision,
            'channel' => 'mic-1',
            'deployment_id' => $this->deployment->uuid,
            'profile_id' => $this->profile->uuid,
            'calibration_id' => $this->calibration?->uuid,
            'configuration_revision' => $this->configuration->revision,
            'detection_state' => $endedAt === null ? 'open' : 'finalized',
            'started_at' => $startedAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'ended_at' => $endedAt?->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'detection' => [
                'rule_version' => 'rules-1',
                'trigger_metric' => $absolute ? 'lafmax_db' : 'rms_dbfs',
                'trigger_kind' => 'baseline_relative',
                'threshold_db' => 15.0,
                'trigger_value_db' => $absolute ? 82.5 : -12.0,
                'baseline_db' => $absolute ? 48.0 : -40.0,
                'baseline_method' => 'rolling 5-minute LAeq median',
            ],
            'summary' => [
                'laeq_db' => $absolute ? 71.2 : null,
                'lafmax_db' => $absolute ? 82.5 : null,
                'lceq_db' => null,
                'lcpeak_db' => null,
                'low_frequency_leq_db' => null,
                'rms_dbfs' => -15.0,
                'duration_ms' => $endedAt ? (int) ($endedAt->getTimestampMs() - $startedAt->getTimestampMs()) : null,
            ],
            'recording' => [
                'expected' => true,
                'started_at' => $startedAt->subSeconds(10)->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'ended_at' => $endedAt?->addSeconds(30)->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'expected_segments' => 1,
            ],
            'quality_flags' => [],
        ], $overrides);
    }
}
