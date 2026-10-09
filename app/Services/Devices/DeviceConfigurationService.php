<?php

namespace App\Services\Devices;

use App\Enums\Metric;
use App\Models\Device;
use App\Models\DeviceConfiguration;
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
 * separately. A configuration carries operational settings only: the device
 * registers its own measurement chain and the server resolves placements, so
 * channels never reference profiles, placements or calibrations.
 */
class DeviceConfigurationService
{
    public const DEFAULT_CHANNEL = 'mic-1';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Default settings for a first configuration, with one channel based on the
     * device's reported capabilities (or its latest registered profile) when known.
     *
     * @return array<string, mixed>
     */
    public function defaults(?Device $device = null): array
    {
        $capabilities = $device?->capabilities;
        $channel = $capabilities['channels'][0] ?? self::DEFAULT_CHANNEL;
        $profile = $device?->measurementProfiles()->where('channel', $channel)->latest('id')->first();
        $metrics = $capabilities['metrics'] ?? $profile?->supported_metrics ?? array_map(fn (Metric $metric): string => $metric->value, Metric::cases());

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
            'channels' => [[
                'channel' => $channel,
                'enabled' => true,
                'metrics' => array_values($metrics),
                'bands_enabled' => false,
            ]],
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
            $channels[] = [
                'channel' => (string) ($channel['channel'] ?? ''),
                'enabled' => (bool) ($channel['enabled'] ?? true),
                'metrics' => array_values($channel['metrics'] ?? []),
                'bands_enabled' => (bool) ($channel['bands_enabled'] ?? false),
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
     * Validate a document against the device's reported capabilities (heartbeat).
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
            $errors['channels'][] = 'At least one channel is required.';
        }

        $seen = [];

        foreach ($document['channels'] as $index => $channel) {
            $path = 'channels.'.$index;

            if (preg_match('/^[A-Za-z0-9._-]{1,32}$/', $channel['channel']) !== 1) {
                $errors[$path.'.channel'][] = 'Use 1–32 characters of A–Z, a–z, 0–9, ".", "_", "-" (e.g. mic-1).';
            } elseif (isset($seen[$channel['channel']])) {
                $errors[$path.'.channel'][] = 'Each channel may appear once.';
            }

            $seen[$channel['channel']] = true;

            if ($channel['metrics'] === []) {
                $errors[$path.'.metrics'][] = 'Choose at least one metric.';
            }

            foreach ($channel['metrics'] as $metric) {
                if (Metric::tryFrom((string) $metric) === null) {
                    $errors[$path.'.metrics'][] = "{$metric} is not a known metric.";
                }
            }

            // The device validates against its own registered chain; the server
            // checks only what the device has reported it can do.
            if ($capabilities !== null) {
                if (isset($capabilities['channels']) && ! in_array($channel['channel'], $capabilities['channels'], true)) {
                    $errors[$path.'.channel'][] = 'The device has not reported this channel among its capabilities ('.implode(', ', $capabilities['channels']).').';
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

        if ($recording['pre_roll_seconds'] < 0 || $recording['pre_roll_seconds'] > 120) {
            $errors['recording.pre_roll_seconds'][] = 'Pre-roll must be 0–120 s.';
        }

        if ($recording['post_roll_seconds'] < 0 || $recording['post_roll_seconds'] > 300) {
            $errors['recording.post_roll_seconds'][] = 'Post-roll must be 0–300 s.';
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
     * @throws ValidationException keyed by settings paths (see settingsErrors())
     */
    public function publish(Device $device, array $settings, ?User $user, ?string $notes = null, ?int $rollbackOf = null): DeviceConfiguration
    {
        return DB::transaction(function () use ($device, $settings, $user, $notes, $rollbackOf): DeviceConfiguration {
            $device = Device::query()->whereKey($device->id)->lockForUpdate()->first();
            $revision = ((int) $device->configurations()->max('revision')) + 1;
            $document = $this->storedForm($this->buildDocument($device, $settings, $revision));
            $result = $this->validate($device, $document);

            if ($result['errors'] !== []) {
                throw ValidationException::withMessages($this->settingsErrors($result['errors'], $settings));
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
     * Map document validation errors to the settings (form) keys they come from,
     * e.g. "detection.absolute.level_db" → "absolute_level_db" and
     * "channels.0.metrics" → "channels.{key of the first channel}.metrics".
     * Unmapped document paths are kept as they are.
     *
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $settings
     * @return array<string, list<string>>
     */
    public function settingsErrors(array $errors, array $settings): array
    {
        $fields = [
            'reporting_interval_seconds' => 'reporting_interval_seconds',
            'heartbeat_interval_seconds' => 'heartbeat_interval_seconds',
            'recording.pre_roll_seconds' => 'pre_roll_seconds',
            'recording.post_roll_seconds' => 'post_roll_seconds',
            'recording.max_segment_duration_seconds' => 'max_segment_duration_seconds',
            'recording.format' => 'recording_format',
            'detection.absolute.level_db' => 'absolute_level_db',
            'detection.baseline_relative.delta_db' => 'relative_delta_db',
        ];
        $channelKeys = array_keys($settings['channels'] ?? []);
        $mapped = [];

        foreach ($errors as $path => $messages) {
            if (isset($fields[$path])) {
                $key = $fields[$path];
            } elseif (preg_match('/^channels\.(\d+)\.(\w+)$/', $path, $matches) === 1 && isset($channelKeys[(int) $matches[1]])) {
                $key = 'channels.'.$channelKeys[(int) $matches[1]].'.'.$matches[2];
            } else {
                $key = $path;
            }

            $mapped[$key] = [...($mapped[$key] ?? []), ...$messages];
        }

        return $mapped;
    }

    /**
     * The document exactly as it is stored and served: a JSON round trip, as the JSON column does.
     *
     * Hashing this form (not the in-memory build, where e.g. delta_db is the float 15.0 but is
     * stored and served as 15) keeps content_hash reproducible from the served document.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function storedForm(array $document): array
    {
        return json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
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
            // Revisions before 2026-10-09 also carry profile/placement/calibration references; they are dropped.
            'channels' => array_map(fn (array $channel): array => [
                'channel' => $channel['channel'] ?? self::DEFAULT_CHANNEL,
                'enabled' => $channel['enabled'] ?? true,
                'metrics' => $channel['metrics'] ?? [],
                'bands_enabled' => $channel['bands_enabled'] ?? false,
            ], $document['channels'] ?? []),
        ];
    }
}
