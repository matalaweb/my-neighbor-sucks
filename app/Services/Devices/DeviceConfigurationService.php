<?php

namespace App\Services\Devices;

use App\Enums\Metric;
use App\Models\Device;
use App\Models\DeviceCalibration;
use App\Models\DeviceConfiguration;
use App\Models\DeviceDeployment;
use App\Models\MeasurementProfile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\CanonicalJson;
use App\Support\Rfc3339;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Declarative, versioned device configuration (spec §14). Every revision is
 * a complete immutable document with a SHA-256 hash; rollback publishes a new
 * revision copying old values. Desired and applied revisions are tracked
 * separately.
 */
class DeviceConfigurationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Default settings for a first configuration.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'reporting_interval_seconds' => config('noise.device_defaults.reporting_interval_seconds'),
            'heartbeat_interval_seconds' => config('noise.device_defaults.heartbeat_interval_seconds'),
            'recording_enabled' => true,
            'recording_format' => 'audio/flac',
            'pre_roll_seconds' => config('noise.device_defaults.pre_roll_seconds'),
            'post_roll_seconds' => config('noise.device_defaults.post_roll_seconds'),
            'max_segment_duration_seconds' => config('noise.device_defaults.max_segment_duration_seconds'),
            'detection_rule_version' => 'owner-rules-v1',
            'absolute_enabled' => false,
            'absolute_metric' => Metric::LAFmax->value,
            'absolute_level_db' => null,
            'relative_enabled' => false,
            'relative_metric' => Metric::LAeq->value,
            'relative_delta_db' => null,
            'baseline_window_seconds' => 300,
            'min_event_duration_ms' => 1000,
            'merge_gap_ms' => 5000,
            'observation_period_until' => null,
            'local_measurement_retention_days' => 7,
            'local_audio_retention_days' => 7,
            'max_local_disk_percent' => 80,
            'channels' => [],
        ];
    }

    /**
     * Build a complete document from owner settings.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function buildDocument(Device $device, array $settings, int $revision): array
    {
        $channels = [];

        foreach ($settings['channels'] ?? [] as $channel) {
            $profile = MeasurementProfile::query()->where('device_id', $device->id)->where('uuid', $channel['measurement_profile_id'])->first();
            $deployment = DeviceDeployment::query()->where('device_id', $device->id)->where('uuid', $channel['deployment_id'])->first();
            $calibration = empty($channel['calibration_id']) ? null : DeviceCalibration::query()->where('device_id', $device->id)->where('uuid', $channel['calibration_id'])->first();

            $channels[] = [
                'channel' => $profile?->channel ?? $channel['channel'] ?? null,
                'enabled' => (bool) ($channel['enabled'] ?? true),
                'metrics' => array_values($channel['metrics'] ?? []),
                'bands_enabled' => (bool) ($channel['bands_enabled'] ?? false),
                'measurement_profile_id' => $profile?->uuid,
                'deployment_id' => $deployment?->uuid,
                'calibration_id' => $calibration?->uuid,
                'calibration_state' => $profile?->calibration_state->value,
            ];
        }

        return [
            'schema_version' => 1,
            'device_id' => $device->uuid,
            'revision' => $revision,
            'reporting_interval_seconds' => (int) $settings['reporting_interval_seconds'],
            'heartbeat_interval_seconds' => (int) $settings['heartbeat_interval_seconds'],
            'measurement_interval_ms' => (int) config('noise.measurements.interval_ms'),
            'channels' => $channels,
            'recording' => [
                'enabled' => (bool) $settings['recording_enabled'],
                'format' => $settings['recording_format'],
                'pre_roll_seconds' => (int) $settings['pre_roll_seconds'],
                'post_roll_seconds' => (int) $settings['post_roll_seconds'],
                'max_segment_duration_seconds' => (int) $settings['max_segment_duration_seconds'],
            ],
            'detection' => [
                'rule_version' => (string) $settings['detection_rule_version'],
                'absolute' => [
                    'enabled' => (bool) $settings['absolute_enabled'],
                    'metric' => $settings['absolute_metric'],
                    'level_db' => $settings['absolute_level_db'] === null || $settings['absolute_level_db'] === '' ? null : (float) $settings['absolute_level_db'],
                ],
                'baseline_relative' => [
                    'enabled' => (bool) $settings['relative_enabled'],
                    'metric' => $settings['relative_metric'],
                    'delta_db' => $settings['relative_delta_db'] === null || $settings['relative_delta_db'] === '' ? null : (float) $settings['relative_delta_db'],
                    'baseline_window_seconds' => (int) $settings['baseline_window_seconds'],
                ],
                'min_event_duration_ms' => (int) $settings['min_event_duration_ms'],
                'merge_gap_ms' => (int) $settings['merge_gap_ms'],
                // Thresholds are owner choices, not legal or universal limits.
                'observation_period_until' => blank($settings['observation_period_until'] ?? null) ? null : Rfc3339::format(CarbonImmutable::parse($settings['observation_period_until'])),
            ],
            'local_retention' => [
                'measurement_days' => (int) $settings['local_measurement_retention_days'],
                'audio_days' => (int) $settings['local_audio_retention_days'],
                'max_disk_usage_percent' => (int) $settings['max_local_disk_percent'],
            ],
        ];
    }

    /**
     * Validate a document against provisioned provenance and reported capabilities.
     *
     * @param  array<string, mixed>  $document
     * @return array{errors: array<string, list<string>>, warnings: list<string>}
     */
    public function validate(Device $device, array $document): array
    {
        $errors = [];
        $warnings = [];
        $capabilities = $device->capabilities;

        if ($document['reporting_interval_seconds'] < 5 || $document['reporting_interval_seconds'] > 300) {
            $errors['reporting_interval_seconds'][] = 'Must be between 5 and 300 seconds.';
        }

        if ($document['heartbeat_interval_seconds'] < 10 || $document['heartbeat_interval_seconds'] > 3600) {
            $errors['heartbeat_interval_seconds'][] = 'Must be between 10 and 3600 seconds.';
        }

        if ($document['channels'] === []) {
            $errors['channels'][] = 'At least one channel with a measurement profile and placement is required.';
        }

        $seen = [];

        foreach ($document['channels'] as $index => $channel) {
            $path = 'channels.'.$index;

            if ($channel['measurement_profile_id'] === null) {
                $errors[$path.'.measurement_profile_id'][] = 'Unknown measurement profile.';

                continue;
            }

            if ($channel['deployment_id'] === null) {
                $errors[$path.'.deployment_id'][] = 'Unknown placement (deployment).';
            }

            if (isset($seen[$channel['channel']])) {
                $errors[$path.'.channel'][] = 'Each channel may appear once.';
            }

            $seen[$channel['channel']] = true;
            $profile = MeasurementProfile::query()->where('uuid', $channel['measurement_profile_id'])->first();

            foreach ($channel['metrics'] as $metric) {
                if (! $profile->supports(Metric::from($metric))) {
                    $errors[$path.'.metrics'][] = "{$metric} is not supported by the selected profile.";
                }

                if (Metric::from($metric)->isAbsolute() && ! $profile->calibration_state->allowsAbsoluteLevels()) {
                    $errors[$path.'.metrics'][] = "{$metric} is an absolute SPL metric; the selected profile is uncalibrated.";
                }
            }

            if ($profile->calibration_state->allowsAbsoluteLevels() && $channel['calibration_id'] === null) {
                $errors[$path.'.calibration_id'][] = 'An '.$profile->calibration_state->value.' profile requires a matching calibration record.';
            }

            if (! $profile->calibration_state->allowsAbsoluteLevels() && $channel['calibration_id'] !== null) {
                $errors[$path.'.calibration_id'][] = 'An uncalibrated profile must not reference a calibration.';
            }

            if ($channel['calibration_id'] !== null) {
                $calibration = DeviceCalibration::query()->where('uuid', $channel['calibration_id'])->first();

                if ($calibration->channel !== $profile->channel || $calibration->calibration_state !== $profile->calibration_state) {
                    $errors[$path.'.calibration_id'][] = 'Calibration must match the profile channel and calibration state.';
                }
            }

            if ($capabilities !== null) {
                if (isset($capabilities['channels']) && ! in_array($channel['channel'], $capabilities['channels'], true)) {
                    $errors[$path.'.channel'][] = 'The device has not reported this channel among its capabilities.';
                }

                foreach (array_diff($channel['metrics'], $capabilities['metrics'] ?? $channel['metrics']) as $metric) {
                    $errors[$path.'.metrics'][] = "The device has not reported support for {$metric}.";
                }

                if ($channel['bands_enabled'] && ! ($capabilities['third_octave_bands'] ?? false)) {
                    $errors[$path.'.bands_enabled'][] = 'The device has not reported third-octave band support.';
                }
            }
        }

        $recording = $document['recording'];

        if ($recording['pre_roll_seconds'] < 0 || $recording['pre_roll_seconds'] > 120 || $recording['post_roll_seconds'] < 0 || $recording['post_roll_seconds'] > 300) {
            $errors['recording'][] = 'Pre-roll must be 0–120 s and post-roll 0–300 s.';
        }

        if ($recording['max_segment_duration_seconds'] < 10 || (int) config('noise.recordings.max_duration_ms') < $recording['max_segment_duration_seconds'] * 1000) {
            $errors['recording.max_segment_duration_seconds'][] = 'Must be between 10 and '.((int) config('noise.recordings.max_duration_ms') / 1000).' seconds.';
        }

        if ($capabilities !== null && $recording['enabled'] && isset($capabilities['recording_formats']) && ! in_array($recording['format'], $capabilities['recording_formats'], true)) {
            $errors['recording.format'][] = 'The device has not reported support for this recording format.';
        }

        $detection = $document['detection'];

        if ($detection['absolute']['enabled'] && $detection['absolute']['level_db'] === null) {
            $errors['detection.absolute.level_db'][] = 'Choose a level for the absolute rule, or disable it.';
        }

        if ($detection['baseline_relative']['enabled'] && $detection['baseline_relative']['delta_db'] === null) {
            $errors['detection.baseline_relative.delta_db'][] = 'Choose a level difference for the baseline-relative rule, or disable it.';
        }

        if ($capabilities === null) {
            $warnings[] = 'The device has not reported capabilities yet; channel/metric support could not be checked.';
        }

        if ($detection['observation_period_until'] === null && ($detection['absolute']['enabled'] || $detection['baseline_relative']['enabled'])) {
            $warnings[] = 'Consider an initial observation period before relying on a detection threshold.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws ValidationException
     */
    public function publish(Device $device, array $settings, ?User $user, ?string $notes = null, ?int $rollbackOf = null): DeviceConfiguration
    {
        return DB::transaction(function () use ($device, $settings, $user, $notes, $rollbackOf): DeviceConfiguration {
            $device = Device::query()->whereKey($device->id)->lockForUpdate()->first();
            $revision = ((int) $device->configurations()->max('revision')) + 1;
            $document = $this->buildDocument($device, $settings, $revision);
            $result = $this->validate($device, $document);

            if ($result['errors'] !== []) {
                throw ValidationException::withMessages(collect($result['errors'])->mapWithKeys(fn (array $messages, string $key): array => ['data.'.$key => $messages])->all());
            }

            $configuration = $device->configurations()->create([
                'account_id' => $device->account_id,
                'revision' => $revision,
                'document' => $document,
                'content_hash' => CanonicalJson::hash($document),
                'rollback_of_revision' => $rollbackOf,
                'notes' => $notes,
                'created_by' => $user?->id,
                'issued_at' => CarbonImmutable::now(),
            ]);

            $device->forceFill([
                'desired_config_revision' => $revision,
                'reporting_interval_seconds' => $document['reporting_interval_seconds'],
                'heartbeat_interval_seconds' => $document['heartbeat_interval_seconds'],
            ])->save();

            $this->audit->record($rollbackOf ? 'device.configuration.rolled_back' : 'device.configuration.published', $configuration, [
                'revision' => $revision,
                'content_hash' => $configuration->content_hash,
                'rollback_of_revision' => $rollbackOf,
            ], user: $user);

            return $configuration;
        });
    }

    /**
     * Rollback = a new revision copying an old revision's settings.
     */
    public function rollback(Device $device, DeviceConfiguration $target, ?User $user): DeviceConfiguration
    {
        return $this->publish($device, $this->settingsFromDocument($target->document), $user, 'Rollback to revision '.$target->revision, $target->revision);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function settingsFromDocument(array $document): array
    {
        return [
            'reporting_interval_seconds' => $document['reporting_interval_seconds'],
            'heartbeat_interval_seconds' => $document['heartbeat_interval_seconds'],
            'recording_enabled' => $document['recording']['enabled'],
            'recording_format' => $document['recording']['format'],
            'pre_roll_seconds' => $document['recording']['pre_roll_seconds'],
            'post_roll_seconds' => $document['recording']['post_roll_seconds'],
            'max_segment_duration_seconds' => $document['recording']['max_segment_duration_seconds'],
            'detection_rule_version' => $document['detection']['rule_version'],
            'absolute_enabled' => $document['detection']['absolute']['enabled'],
            'absolute_metric' => $document['detection']['absolute']['metric'],
            'absolute_level_db' => $document['detection']['absolute']['level_db'],
            'relative_enabled' => $document['detection']['baseline_relative']['enabled'],
            'relative_metric' => $document['detection']['baseline_relative']['metric'],
            'relative_delta_db' => $document['detection']['baseline_relative']['delta_db'],
            'baseline_window_seconds' => $document['detection']['baseline_relative']['baseline_window_seconds'],
            'min_event_duration_ms' => $document['detection']['min_event_duration_ms'],
            'merge_gap_ms' => $document['detection']['merge_gap_ms'],
            'observation_period_until' => $document['detection']['observation_period_until'],
            'local_measurement_retention_days' => $document['local_retention']['measurement_days'],
            'local_audio_retention_days' => $document['local_retention']['audio_days'],
            'max_local_disk_percent' => $document['local_retention']['max_disk_usage_percent'],
            'channels' => array_map(fn (array $channel): array => [
                'channel' => $channel['channel'],
                'enabled' => $channel['enabled'],
                'metrics' => $channel['metrics'],
                'bands_enabled' => $channel['bands_enabled'],
                'measurement_profile_id' => $channel['measurement_profile_id'],
                'deployment_id' => $channel['deployment_id'],
                'calibration_id' => $channel['calibration_id'],
            ], $document['channels']),
        ];
    }

    /**
     * Provenance records referenced by a configuration, with immutable details,
     * so the agent can tag readings without guessing.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, list<array<string, mixed>>>
     */
    public function provenance(Device $device, array $document): array
    {
        $profileIds = array_filter(array_column($document['channels'], 'measurement_profile_id'));
        $deploymentIds = array_filter(array_column($document['channels'], 'deployment_id'));
        $calibrationIds = array_filter(array_column($document['channels'], 'calibration_id'));

        return [
            'measurement_profiles' => MeasurementProfile::query()->where('device_id', $device->id)->whereIn('uuid', $profileIds)->get()->map(fn (MeasurementProfile $profile): array => [
                'id' => $profile->uuid,
                'channel' => $profile->channel,
                'revision' => $profile->revision,
                'calibration_state' => $profile->calibration_state->value,
                'supported_metrics' => $profile->supported_metrics,
                'sample_rate_hz' => $profile->sample_rate_hz,
                'gain_db' => $profile->gain_db === null ? null : (float) $profile->gain_db,
                'low_frequency_band_hz' => $profile->low_frequency_lower_hz === null ? null : [(float) $profile->low_frequency_lower_hz, (float) $profile->low_frequency_upper_hz],
                'band_definitions' => $profile->band_definitions,
                'content_hash' => $profile->content_hash,
            ])->values()->all(),
            'deployments' => DeviceDeployment::query()->where('device_id', $device->id)->whereIn('uuid', $deploymentIds)->get()->map(fn (DeviceDeployment $deployment): array => [
                'id' => $deployment->uuid,
                'revision' => $deployment->revision,
                'effective_at' => Rfc3339::format($deployment->effective_at),
                'content_hash' => $deployment->content_hash,
            ])->values()->all(),
            'calibrations' => DeviceCalibration::query()->where('device_id', $device->id)->whereIn('uuid', $calibrationIds)->get()->map(fn (DeviceCalibration $calibration): array => [
                'id' => $calibration->uuid,
                'channel' => $calibration->channel,
                'revision' => $calibration->revision,
                'calibration_state' => $calibration->calibration_state->value,
                'content_hash' => $calibration->content_hash,
            ])->values()->all(),
        ];
    }
}
