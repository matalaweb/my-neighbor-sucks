<?php

namespace App\Services\Devices;

use App\Enums\CalibrationState;
use App\Models\CalibrationFieldCheck;
use App\Models\Device;
use App\Models\DeviceCalibration;
use App\Models\DeviceDeployment;
use App\Models\MeasurementProfile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates immutable provenance revisions (placement, measurement profile,
 * calibration) with sequential revision numbers and content hashes. Changes
 * apply prospectively; historical readings keep their original references.
 */
class ProvenanceRecords
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDeployment(Device $device, array $data, ?User $user): DeviceDeployment
    {
        return DB::transaction(function () use ($device, $data, $user): DeviceDeployment {
            Device::query()->whereKey($device->id)->lockForUpdate()->first();

            $attributes = [
                'room' => $data['room'] ?? null,
                'location_type' => $data['location_type'],
                'placement_description' => $data['placement_description'] ?? null,
                'mounting_notes' => $data['mounting_notes'] ?? null,
                'height_m' => $data['height_m'] ?? null,
                'orientation' => $data['orientation'] ?? null,
                'effective_at' => CarbonImmutable::parse($data['effective_at'] ?? 'now')->utc(),
            ];

            $deployment = $device->deployments()->create([
                ...$attributes,
                'account_id' => $device->account_id,
                'revision' => ((int) $device->deployments()->max('revision')) + 1,
                'content_hash' => CanonicalJson::hash([...$attributes, 'effective_at' => $attributes['effective_at']->format('Y-m-d\TH:i:s.u\Z')]),
                'created_by' => $user?->id,
            ]);

            $this->audit->record('device.deployment.created', $deployment, ['revision' => $deployment->revision], user: $user);

            return $deployment;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createProfile(Device $device, array $data, ?User $user): MeasurementProfile
    {
        $state = $data['calibration_state'] instanceof CalibrationState ? $data['calibration_state'] : CalibrationState::from($data['calibration_state']);
        $metrics = array_values($data['supported_metrics'] ?? []);

        if (! $state->allowsAbsoluteLevels() && array_diff($metrics, ['rms_dbfs']) !== []) {
            throw new InvalidArgumentException('An uncalibrated profile can only report rms_dbfs; absolute SPL metrics must stay null.');
        }

        $bands = array_values(array_filter($data['band_centers_hz'] ?? [], fn ($center): bool => $center !== null && $center !== ''));

        return DB::transaction(function () use ($device, $data, $user, $state, $metrics, $bands): MeasurementProfile {
            Device::query()->whereKey($device->id)->lockForUpdate()->first();

            $attributes = [
                'channel' => $data['channel'],
                'name' => $data['name'] ?? null,
                'microphone_model' => $data['microphone_model'],
                'microphone_serial' => $data['microphone_serial'] ?? null,
                'audio_interface' => $data['audio_interface'] ?? null,
                'sample_rate_hz' => (int) $data['sample_rate_hz'],
                'gain_db' => $data['gain_db'] ?? null,
                'gain_description' => $data['gain_description'] ?? null,
                'weighting_implementation_version' => $data['weighting_implementation_version'],
                'filter_implementation_version' => $data['filter_implementation_version'],
                'calibration_state' => $state->value,
                'calibration_application_method' => $data['calibration_application_method'] ?? null,
                'supported_metrics' => $metrics,
                'low_frequency_lower_hz' => $data['low_frequency_lower_hz'] ?? null,
                'low_frequency_upper_hz' => $data['low_frequency_upper_hz'] ?? null,
                'band_definitions' => $bands === [] ? null : [
                    'kind' => 'third_octave',
                    'standard' => $data['band_standard'] ?? 'IEC 61260-1 nominal centres',
                    'weighting' => $data['band_weighting'] ?? 'Z',
                    'filter_implementation_version' => $data['filter_implementation_version'],
                    'bands' => array_map(fn ($center): array => ['center_hz' => (float) $center == (int) $center ? (int) $center : (float) $center], $bands),
                ],
                'agent_processing_version' => $data['agent_processing_version'],
            ];

            $profile = $device->measurementProfiles()->create([
                ...$attributes,
                'account_id' => $device->account_id,
                'revision' => ((int) $device->measurementProfiles()->where('channel', $data['channel'])->max('revision')) + 1,
                'content_hash' => CanonicalJson::hash($attributes),
                'created_by' => $user?->id,
            ]);

            $this->audit->record('device.profile.created', $profile, ['revision' => $profile->revision, 'channel' => $profile->channel], user: $user);

            return $profile;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCalibration(Device $device, array $data, ?User $user): DeviceCalibration
    {
        $state = $data['calibration_state'] instanceof CalibrationState ? $data['calibration_state'] : CalibrationState::from($data['calibration_state']);

        if ($state === CalibrationState::Uncalibrated) {
            throw new InvalidArgumentException('Calibration records describe estimated or calibrated chains; uncalibrated profiles need no calibration record.');
        }

        return DB::transaction(function () use ($device, $data, $user, $state): DeviceCalibration {
            Device::query()->whereKey($device->id)->lockForUpdate()->first();

            $attributes = [
                'channel' => $data['channel'],
                'calibration_state' => $state->value,
                'reference_method' => $data['reference_method'],
                'reference_device' => $data['reference_device'] ?? null,
                'reference_level_db' => $data['reference_level_db'] ?? null,
                'reference_frequency_hz' => $data['reference_frequency_hz'] ?? null,
                'sensitivity_mv_per_pa' => $data['sensitivity_mv_per_pa'] ?? null,
                'sensitivity_dbfs_at_94db' => $data['sensitivity_dbfs_at_94db'] ?? null,
                'gain_configuration' => $data['gain_configuration'] ?? null,
                'application_method' => $data['application_method'] ?? null,
                'correction_metadata' => $data['correction_metadata'] ?? null,
                'performed_at' => isset($data['performed_at']) ? CarbonImmutable::parse($data['performed_at'])->utc() : null,
                'performed_by' => $data['performed_by'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            $calibration = $device->calibrations()->create([
                ...$attributes,
                'account_id' => $device->account_id,
                'revision' => ((int) $device->calibrations()->where('channel', $data['channel'])->max('revision')) + 1,
                'content_hash' => CanonicalJson::hash([...$attributes, 'performed_at' => $attributes['performed_at']?->format('Y-m-d\TH:i:s.u\Z')]),
                'created_by' => $user?->id,
            ]);

            $this->audit->record('device.calibration.created', $calibration, ['revision' => $calibration->revision, 'state' => $state->value], user: $user);

            return $calibration;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addFieldCheck(DeviceCalibration $calibration, array $data, ?User $user): CalibrationFieldCheck
    {
        $check = $calibration->fieldChecks()->create([
            'account_id' => $calibration->account_id,
            'checked_at' => CarbonImmutable::parse($data['checked_at'] ?? 'now')->utc(),
            'reference_source' => $data['reference_source'],
            'expected_level_db' => $data['expected_level_db'] ?? null,
            'measured_level_db' => $data['measured_level_db'] ?? null,
            'passed' => $data['passed'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $user?->id,
        ]);

        $this->audit->record('device.calibration.field_check', $check, ['calibration_uuid' => $calibration->uuid], user: $user);

        return $check;
    }
}
