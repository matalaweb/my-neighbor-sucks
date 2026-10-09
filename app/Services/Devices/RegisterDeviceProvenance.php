<?php

namespace App\Services\Devices;

use App\Enums\CalibrationState;
use App\Enums\Metric;
use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\ErrorCode;
use App\Http\DeviceApi\SchemaVersion;
use App\Models\Device;
use App\Models\DeviceCalibration;
use App\Models\MeasurementProfile;
use App\Services\Storage\AttachmentStore;
use App\Services\Storage\EvidenceStorage;
use App\Support\CanonicalJson;
use App\Support\Rfc3339;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Registers the device's own measurement chain (POST /provenance). The device
 * is the source of truth for its measurement profiles and calibrations:
 * records carry device-generated UUIDs, are immutable, and are idempotent by
 * content hash. A known UUID with different content, or a UUID owned by
 * another device, is a conflict; nothing of the request is stored then.
 */
class RegisterDeviceProvenance
{
    public const MAX_RECORDS = 8;

    public const MAX_ATTACHMENTS = 4;

    public const MAX_ATTACHMENT_BYTES = 1024 * 1024;

    public const ATTACHMENT_PURPOSES = ['frequency_response', 'certificate', 'photo', 'other'];

    private const PROFILE_FIELDS = [
        'id', 'channel', 'name', 'microphone_model', 'microphone_serial', 'audio_interface', 'sample_rate_hz', 'gain_db',
        'gain_description', 'weighting_implementation_version', 'filter_implementation_version', 'agent_processing_version',
        'calibration_state', 'calibration_application_method', 'supported_metrics', 'low_frequency_lower_hz',
        'low_frequency_upper_hz', 'band_centers_hz',
    ];

    private const CALIBRATION_FIELDS = [
        'id', 'channel', 'calibration_state', 'reference_method', 'reference_device', 'reference_level_db',
        'reference_frequency_hz', 'sensitivity_mv_per_pa', 'sensitivity_dbfs_at_94db', 'gain_configuration',
        'application_method', 'performed_at', 'performed_by', 'notes', 'attachments',
    ];

    private const ATTACHMENT_FIELDS = ['purpose', 'filename', 'media_type', 'sha256', 'content_base64'];

    public function __construct(
        private readonly ProvenanceRecords $records,
        private readonly AttachmentStore $attachments,
        private readonly EvidenceStorage $storage,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{measurement_profiles: list<array{id: string, revision: int, status: string}>, calibrations: list<array{id: string, revision: int, status: string}>}
     */
    public function handle(Device $device, array $payload): array
    {
        SchemaVersion::assert($payload);

        [$profiles, $calibrations] = $this->validated($payload);
        $storedKeys = [];

        try {
            return DB::transaction(function () use ($device, $profiles, $calibrations, &$storedKeys): array {
                Device::query()->whereKey($device->id)->lockForUpdate()->first();

                $existingProfiles = $this->existing(MeasurementProfile::class, $device, $profiles, 'measurement_profiles');
                $existingCalibrations = $this->existing(DeviceCalibration::class, $device, $calibrations, 'calibrations');
                $result = ['measurement_profiles' => [], 'calibrations' => []];

                foreach ($profiles as $profile) {
                    $record = $existingProfiles[$profile['id']]
                        ?? $this->records->registerDeviceProfile($device, $profile['id'], $profile['record'], $profile['content_hash']);

                    $result['measurement_profiles'][] = ['id' => $profile['id'], 'revision' => $record->revision, 'status' => isset($existingProfiles[$profile['id']]) ? 'existing' : 'created'];
                }

                foreach ($calibrations as $calibration) {
                    $record = $existingCalibrations[$calibration['id']] ?? null;
                    $status = $record === null ? 'created' : 'existing';

                    if ($record === null) {
                        $record = $this->records->registerDeviceCalibration($device, $calibration['id'], $calibration['record'], $calibration['content_hash']);

                        foreach ($calibration['files'] as $file) {
                            $attachment = $this->attachments->storeContents($record, $file['bytes'], $file['filename'], $file['media_type'], $file['purpose'], null);
                            $storedKeys[] = $attachment->object_key;
                        }
                    }

                    $result['calibrations'][] = ['id' => $calibration['id'], 'revision' => $record->revision, 'status' => $status];
                }

                return $result;
            });
        } catch (UniqueConstraintViolationException) {
            $this->discard($storedKeys);

            // A concurrent registration of the same UUID by another device.
            throw new DeviceApiException(ErrorCode::ProvenanceConflict, 'A provenance id is already registered; nothing was stored.');
        } catch (Throwable $exception) {
            $this->discard($storedKeys);

            throw $exception;
        }
    }

    /**
     * Already registered records by UUID; throws on any conflict before anything is written.
     *
     * @param  class-string<MeasurementProfile|DeviceCalibration>  $modelClass
     * @param  list<array{id: string, content_hash: string}>  $items
     * @return array<string, MeasurementProfile|DeviceCalibration>
     */
    private function existing(string $modelClass, Device $device, array $items, string $collection): array
    {
        if ($items === []) {
            return [];
        }

        $found = $modelClass::query()->whereIn('uuid', array_column($items, 'id'))->get()->keyBy('uuid');
        $conflicts = [];

        foreach ($items as $index => $item) {
            $record = $found->get($item['id']);

            if ($record === null) {
                continue;
            }

            if ((int) $record->device_id !== $device->id) {
                $conflicts[] = ['path' => $collection.'.'.$index.'.id', 'id' => $item['id'], 'reason' => 'registered_by_another_device'];
            } elseif (! hash_equals($record->content_hash, $item['content_hash'])) {
                $conflicts[] = ['path' => $collection.'.'.$index.'.id', 'id' => $item['id'], 'reason' => 'content_changed'];
            }
        }

        if ($conflicts !== []) {
            throw new DeviceApiException(
                ErrorCode::ProvenanceConflict,
                'Provenance records are immutable: an id is already registered with different content or by another device. Register changed content under a new id; nothing was stored.',
                ['conflicts' => $conflicts],
            );
        }

        return $found->all();
    }

    /**
     * @param  list<string>  $keys
     */
    private function discard(array $keys): void
    {
        foreach ($keys as $key) {
            try {
                $this->storage->disk()->delete($key);
            } catch (Throwable) {
                // Best effort: an orphaned object is harmless and never referenced.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: list<array{id: string, content_hash: string, record: array<string, mixed>}>, 1: list<array{id: string, content_hash: string, record: array<string, mixed>, files: list<array{purpose: string, filename: string, media_type: string|null, bytes: string}>}>}
     */
    private function validated(array $payload): array
    {
        $errors = [];

        foreach (array_diff(array_keys($payload), ['schema_version', 'sent_at', 'measurement_profiles', 'calibrations']) as $unknown) {
            $errors[$unknown][] = 'Unknown field.';
        }

        $number = fn (float $min, float $max): Closure => function (string $attribute, mixed $value, Closure $fail) use ($min, $max): void {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                $fail('Must be a finite JSON number or null.');
            } elseif ($value < $min || $value > $max) {
                $fail("Must be between {$min} and {$max}.");
            }
        };
        $channel = ['required', 'string', 'regex:/^[A-Za-z0-9._-]{1,32}$/'];
        $text = fn (int $max): array => ['nullable', 'string', 'max:'.$max];
        $metrics = implode(',', array_map(fn (Metric $metric): string => $metric->value, Metric::cases()));

        $validator = Validator::make($payload, [
            'schema_version' => ['required', 'integer'],
            'sent_at' => ['nullable', 'string'],
            'measurement_profiles' => ['nullable', 'array', 'list', 'max:'.self::MAX_RECORDS],
            'measurement_profiles.*' => ['array:'.implode(',', self::PROFILE_FIELDS)],
            'measurement_profiles.*.id' => ['required', 'string', 'uuid', 'distinct:ignore_case'],
            'measurement_profiles.*.channel' => $channel,
            'measurement_profiles.*.name' => $text(255),
            'measurement_profiles.*.microphone_model' => ['required', 'string', 'max:255'],
            'measurement_profiles.*.microphone_serial' => $text(255),
            'measurement_profiles.*.audio_interface' => $text(255),
            'measurement_profiles.*.sample_rate_hz' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'measurement_profiles.*.gain_db' => ['nullable', $number(-9999.99, 9999.99)],
            'measurement_profiles.*.gain_description' => $text(255),
            'measurement_profiles.*.weighting_implementation_version' => ['required', 'string', 'max:64'],
            'measurement_profiles.*.filter_implementation_version' => ['required', 'string', 'max:64'],
            'measurement_profiles.*.agent_processing_version' => ['required', 'string', 'max:64'],
            'measurement_profiles.*.calibration_state' => ['required', 'string', 'in:'.implode(',', array_column(CalibrationState::cases(), 'value'))],
            'measurement_profiles.*.calibration_application_method' => $text(255),
            'measurement_profiles.*.supported_metrics' => ['required', 'array', 'list', 'min:1'],
            'measurement_profiles.*.supported_metrics.*' => ['string', 'in:'.$metrics],
            'measurement_profiles.*.low_frequency_lower_hz' => ['nullable', $number(0, 999999.99)],
            'measurement_profiles.*.low_frequency_upper_hz' => ['nullable', $number(0, 999999.99)],
            'measurement_profiles.*.band_centers_hz' => ['nullable', 'array', 'list'],
            'measurement_profiles.*.band_centers_hz.*' => [$number(0, 999999.99)],
            'calibrations' => ['nullable', 'array', 'list', 'max:'.self::MAX_RECORDS],
            'calibrations.*' => ['array:'.implode(',', self::CALIBRATION_FIELDS)],
            'calibrations.*.id' => ['required', 'string', 'uuid', 'distinct:ignore_case'],
            'calibrations.*.channel' => $channel,
            'calibrations.*.calibration_state' => ['required', 'string', 'in:'.CalibrationState::Estimated->value.','.CalibrationState::Calibrated->value],
            'calibrations.*.reference_method' => ['required', 'string', 'max:255'],
            'calibrations.*.reference_device' => $text(255),
            'calibrations.*.reference_level_db' => ['nullable', $number(-9999.99, 9999.99)],
            'calibrations.*.reference_frequency_hz' => ['nullable', $number(0, 999999.99)],
            'calibrations.*.sensitivity_mv_per_pa' => ['nullable', $number(-999999.9999, 999999.9999)],
            'calibrations.*.sensitivity_dbfs_at_94db' => ['nullable', $number(-99999.999, 99999.999)],
            'calibrations.*.gain_configuration' => $text(255),
            'calibrations.*.application_method' => $text(255),
            'calibrations.*.performed_at' => ['nullable', 'string'],
            'calibrations.*.performed_by' => $text(255),
            'calibrations.*.notes' => $text(10000),
            'calibrations.*.attachments' => ['nullable', 'array', 'list', 'max:'.self::MAX_ATTACHMENTS],
            'calibrations.*.attachments.*' => ['array:'.implode(',', self::ATTACHMENT_FIELDS)],
            'calibrations.*.attachments.*.purpose' => ['required', 'string', 'in:'.implode(',', self::ATTACHMENT_PURPOSES)],
            'calibrations.*.attachments.*.filename' => ['required', 'string', 'max:255'],
            'calibrations.*.attachments.*.media_type' => $text(128),
            'calibrations.*.attachments.*.sha256' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'calibrations.*.attachments.*.content_base64' => ['required', 'string'],
        ]);

        $errors = array_merge_recursive($errors, $validator->errors()->toArray());
        $rawProfiles = is_array($payload['measurement_profiles'] ?? null) ? $payload['measurement_profiles'] : [];
        $rawCalibrations = is_array($payload['calibrations'] ?? null) ? $payload['calibrations'] : [];

        if ($rawProfiles === [] && $rawCalibrations === []) {
            $errors['measurement_profiles'][] = 'Register at least one measurement profile or calibration.';
        }

        if (($payload['sent_at'] ?? null) !== null && Rfc3339::parse($payload['sent_at']) === null) {
            $errors['sent_at'][] = 'Must be an RFC 3339 timestamp.';
        }

        $profileIds = [];

        foreach ($rawProfiles as $index => $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $profileIds[] = strtolower((string) ($profile['id'] ?? ''));
            $state = CalibrationState::tryFrom((string) ($profile['calibration_state'] ?? ''));
            $supported = is_array($profile['supported_metrics'] ?? null) ? $profile['supported_metrics'] : [];

            // "distinct" rules would compare across all profiles of the request, so check per profile.
            if (count($supported) !== count(array_unique($supported, SORT_REGULAR))) {
                $errors['measurement_profiles.'.$index.'.supported_metrics'][] = 'Each metric may be listed once.';
            }

            if ($state === CalibrationState::Uncalibrated && array_diff($supported, [Metric::RmsDbfs->value]) !== []) {
                $errors['measurement_profiles.'.$index.'.supported_metrics'][] = 'An uncalibrated profile can only report rms_dbfs; absolute SPL metrics must stay null.';
            }

            $lower = $profile['low_frequency_lower_hz'] ?? null;
            $upper = $profile['low_frequency_upper_hz'] ?? null;

            if (is_numeric($lower) && is_numeric($upper) && $upper <= $lower) {
                $errors['measurement_profiles.'.$index.'.low_frequency_upper_hz'][] = 'Must be above the lower band edge.';
            }

            $allowedCenters = array_map(fn ($center): string => (string) (float) $center, config('noise.measurements.third_octave_centers_hz'));
            $seenCenters = [];

            foreach (is_array($profile['band_centers_hz'] ?? null) ? $profile['band_centers_hz'] : [] as $bandIndex => $center) {
                if (! is_numeric($center)) {
                    continue;
                }

                if (! in_array((string) (float) $center, $allowedCenters, true)) {
                    $errors['measurement_profiles.'.$index.'.band_centers_hz.'.$bandIndex][] = 'Must be one of the nominal third-octave centres '.implode(', ', $allowedCenters).' Hz.';
                } elseif (isset($seenCenters[(string) (float) $center])) {
                    $errors['measurement_profiles.'.$index.'.band_centers_hz.'.$bandIndex][] = 'Each band centre may be listed once.';
                }

                $seenCenters[(string) (float) $center] = true;
            }
        }

        $files = [];

        foreach ($rawCalibrations as $index => $calibration) {
            if (! is_array($calibration)) {
                continue;
            }

            if (in_array(strtolower((string) ($calibration['id'] ?? '')), $profileIds, true)) {
                $errors['calibrations.'.$index.'.id'][] = 'Already used by a measurement profile in this request.';
            }

            if (($calibration['performed_at'] ?? null) !== null && is_string($calibration['performed_at']) && Rfc3339::parse($calibration['performed_at']) === null) {
                $errors['calibrations.'.$index.'.performed_at'][] = 'Must be an RFC 3339 timestamp.';
            }

            foreach (is_array($calibration['attachments'] ?? null) ? $calibration['attachments'] : [] as $attachmentIndex => $attachment) {
                $path = 'calibrations.'.$index.'.attachments.'.$attachmentIndex;

                if (! is_array($attachment) || ! is_string($attachment['content_base64'] ?? null)) {
                    continue;
                }

                $bytes = base64_decode($attachment['content_base64'], true);

                if ($bytes === false) {
                    $errors[$path.'.content_base64'][] = 'Must be standard base64.';

                    continue;
                }

                if (strlen($bytes) > self::MAX_ATTACHMENT_BYTES) {
                    $errors[$path.'.content_base64'][] = 'Decoded content may be at most '.self::MAX_ATTACHMENT_BYTES.' bytes.';

                    continue;
                }

                if (is_string($attachment['sha256'] ?? null) && ! hash_equals(strtolower($attachment['sha256']), hash('sha256', $bytes))) {
                    $errors[$path.'.sha256'][] = 'Does not match the SHA-256 of the decoded content.';
                }

                $files[$index][$attachmentIndex] = $bytes;
            }
        }

        if ($errors !== []) {
            throw new DeviceApiException(ErrorCode::ValidationFailed, 'The provenance registration failed validation; nothing was stored.', ['errors' => $errors]);
        }

        $profiles = array_map(fn (array $profile): array => $this->normalizedProfile($profile), $rawProfiles);
        $calibrations = array_map(fn (array $calibration, int $index): array => $this->normalizedCalibration($calibration, $files[$index] ?? []), $rawCalibrations, array_keys($rawCalibrations));

        return [array_values($profiles), array_values($calibrations)];
    }

    /**
     * The registered record (without its id), normalized so retries hash identically.
     *
     * @param  array<string, mixed>  $profile
     * @return array{id: string, content_hash: string, record: array<string, mixed>}
     */
    private function normalizedProfile(array $profile): array
    {
        $metrics = array_values(array_filter(
            array_map(fn (Metric $metric): string => $metric->value, Metric::cases()),
            fn (string $metric): bool => in_array($metric, $profile['supported_metrics'], true),
        ));
        $bands = array_map(fn ($center): float => (float) $center, $profile['band_centers_hz'] ?? []);
        sort($bands);

        $record = [
            'channel' => $profile['channel'],
            'name' => $profile['name'] ?? null,
            'microphone_model' => $profile['microphone_model'],
            'microphone_serial' => $profile['microphone_serial'] ?? null,
            'audio_interface' => $profile['audio_interface'] ?? null,
            'sample_rate_hz' => (int) $profile['sample_rate_hz'],
            'gain_db' => self::float($profile['gain_db'] ?? null),
            'gain_description' => $profile['gain_description'] ?? null,
            'weighting_implementation_version' => $profile['weighting_implementation_version'],
            'filter_implementation_version' => $profile['filter_implementation_version'],
            'agent_processing_version' => $profile['agent_processing_version'],
            'calibration_state' => $profile['calibration_state'],
            'calibration_application_method' => $profile['calibration_application_method'] ?? null,
            'supported_metrics' => $metrics,
            'low_frequency_lower_hz' => self::float($profile['low_frequency_lower_hz'] ?? null),
            'low_frequency_upper_hz' => self::float($profile['low_frequency_upper_hz'] ?? null),
            'band_centers_hz' => array_values($bands),
        ];

        return ['id' => strtolower($profile['id']), 'content_hash' => CanonicalJson::hash($record), 'record' => $record];
    }

    /**
     * @param  array<string, mixed>  $calibration
     * @param  array<int, string>  $files  decoded attachment bytes by attachment index
     * @return array{id: string, content_hash: string, record: array<string, mixed>, files: list<array{purpose: string, filename: string, media_type: string|null, bytes: string}>}
     */
    private function normalizedCalibration(array $calibration, array $files): array
    {
        $attachments = [];

        foreach ($calibration['attachments'] ?? [] as $index => $attachment) {
            $attachments[] = [
                'purpose' => $attachment['purpose'],
                'filename' => $attachment['filename'],
                'media_type' => $attachment['media_type'] ?? null,
                'sha256' => strtolower($attachment['sha256']),
                'bytes' => $files[$index],
            ];
        }

        usort($attachments, fn (array $a, array $b): int => [$a['purpose'], $a['filename'], $a['sha256']] <=> [$b['purpose'], $b['filename'], $b['sha256']]);

        $record = [
            'channel' => $calibration['channel'],
            'calibration_state' => $calibration['calibration_state'],
            'reference_method' => $calibration['reference_method'],
            'reference_device' => $calibration['reference_device'] ?? null,
            'reference_level_db' => self::float($calibration['reference_level_db'] ?? null),
            'reference_frequency_hz' => self::float($calibration['reference_frequency_hz'] ?? null),
            'sensitivity_mv_per_pa' => self::float($calibration['sensitivity_mv_per_pa'] ?? null),
            'sensitivity_dbfs_at_94db' => self::float($calibration['sensitivity_dbfs_at_94db'] ?? null),
            'gain_configuration' => $calibration['gain_configuration'] ?? null,
            'application_method' => $calibration['application_method'] ?? null,
            'performed_at' => Rfc3339::formatMicro(Rfc3339::parse($calibration['performed_at'] ?? null)),
            'performed_by' => $calibration['performed_by'] ?? null,
            'notes' => $calibration['notes'] ?? null,
        ];

        $hashed = [...$record, 'attachments' => array_map(fn (array $attachment): array => [
            'purpose' => $attachment['purpose'],
            'filename' => $attachment['filename'],
            'sha256' => $attachment['sha256'],
        ], $attachments)];

        return [
            'id' => strtolower($calibration['id']),
            'content_hash' => CanonicalJson::hash($hashed),
            'record' => $record,
            'files' => array_map(fn (array $attachment): array => array_diff_key($attachment, ['sha256' => true]), $attachments),
        ];
    }

    private static function float(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
