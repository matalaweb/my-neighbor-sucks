<?php

namespace App\Services\Devices;

use App\Enums\ClockSyncState;
use App\Enums\MicrophoneState;
use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\ErrorCode;
use App\Http\DeviceApi\SchemaVersion;
use App\Models\Device;
use App\Models\DeviceHeartbeat;
use App\Support\Rfc3339;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Stores device health telemetry. Device-reported times are stored
 * separately from server receipt time; the server never infers clock
 * correctness from request arrival.
 */
class RecordHeartbeat
{
    private const FIELDS = [
        'schema_version', 'sent_at', 'agent_version', 'boot_id', 'uptime_seconds', 'capabilities', 'microphone_state',
        'free_disk_bytes', 'total_disk_bytes', 'queued_measurement_count', 'pending_audio_bytes', 'pending_audio_count',
        'oldest_pending_capture_at', 'desired_config_revision', 'applied_config_revision', 'clock', 'recent_dropped_intervals',
        'last_capture_error',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(Device $device, array $payload, CarbonImmutable $receivedAt): array
    {
        SchemaVersion::assert($payload);

        $errors = [];

        foreach (array_diff(array_keys($payload), self::FIELDS) as $unknown) {
            $errors[$unknown][] = 'Unknown field.';
        }

        $validator = Validator::make($payload, [
            'schema_version' => ['required', 'integer', 'in:'.config('noise.device_api.schema_version')],
            'sent_at' => ['nullable', 'string'],
            'agent_version' => ['required', 'string', 'max:64'],
            'boot_id' => ['nullable', 'uuid'],
            'uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'capabilities' => ['required', 'array'],
            'capabilities.channels' => ['required', 'array', 'list'],
            'capabilities.channels.*' => ['string', 'max:32'],
            'capabilities.metrics' => ['required', 'array', 'list'],
            'capabilities.metrics.*' => ['string', 'in:laeq_db,lafmax_db,lceq_db,lcpeak_db,low_frequency_leq_db,rms_dbfs'],
            'capabilities.third_octave_bands' => ['nullable', 'boolean'],
            'capabilities.recording_formats' => ['nullable', 'array', 'list'],
            'capabilities.recording_formats.*' => ['string', 'in:audio/wav,audio/flac'],
            'capabilities.max_sample_rate_hz' => ['nullable', 'integer'],
            'microphone_state' => ['required', 'string', 'in:ok,disconnected,error,unknown'],
            'free_disk_bytes' => ['nullable', 'integer', 'min:0'],
            'total_disk_bytes' => ['nullable', 'integer', 'min:0'],
            'queued_measurement_count' => ['nullable', 'integer', 'min:0'],
            'pending_audio_bytes' => ['nullable', 'integer', 'min:0'],
            'pending_audio_count' => ['nullable', 'integer', 'min:0'],
            'oldest_pending_capture_at' => ['nullable', 'string'],
            'desired_config_revision' => ['nullable', 'integer', 'min:1'],
            'applied_config_revision' => ['nullable', 'integer', 'min:1'],
            'clock' => ['required', 'array:sync_state,offset_ms,source'],
            'clock.sync_state' => ['required', 'string', 'in:synchronized,unsynchronized,unknown'],
            'clock.offset_ms' => ['nullable', 'integer'],
            'clock.source' => ['nullable', 'string', 'max:64'],
            'recent_dropped_intervals' => ['nullable', 'integer', 'min:0'],
            'last_capture_error' => ['nullable', 'string', 'max:2000'],
        ]);

        $errors = array_merge_recursive($errors, $validator->errors()->toArray());

        foreach (['sent_at', 'oldest_pending_capture_at'] as $field) {
            if (($payload[$field] ?? null) !== null && Rfc3339::parse($payload[$field]) === null) {
                $errors[$field][] = 'Must be an RFC 3339 timestamp.';
            }
        }

        if ($errors !== []) {
            throw new DeviceApiException(ErrorCode::ValidationFailed, 'The heartbeat failed validation.', ['errors' => $errors]);
        }

        DB::transaction(function () use ($device, $payload, $receivedAt): void {
            $heartbeat = DeviceHeartbeat::query()->create([
                'account_id' => $device->account_id,
                'device_id' => $device->id,
                'agent_version' => $payload['agent_version'],
                'boot_id' => isset($payload['boot_id']) ? strtolower($payload['boot_id']) : null,
                'uptime_seconds' => $payload['uptime_seconds'] ?? null,
                'capabilities' => $payload['capabilities'],
                'microphone_state' => MicrophoneState::from($payload['microphone_state']),
                'free_disk_bytes' => $payload['free_disk_bytes'] ?? null,
                'total_disk_bytes' => $payload['total_disk_bytes'] ?? null,
                'queued_measurement_count' => $payload['queued_measurement_count'] ?? null,
                'pending_audio_bytes' => $payload['pending_audio_bytes'] ?? null,
                'pending_audio_count' => $payload['pending_audio_count'] ?? null,
                'oldest_pending_capture_at' => Rfc3339::parse($payload['oldest_pending_capture_at'] ?? null),
                'desired_config_revision' => $payload['desired_config_revision'] ?? null,
                'applied_config_revision' => $payload['applied_config_revision'] ?? null,
                'clock_sync_state' => ClockSyncState::from($payload['clock']['sync_state']),
                'clock_offset_ms' => $payload['clock']['offset_ms'] ?? null,
                'recent_dropped_intervals' => $payload['recent_dropped_intervals'] ?? null,
                'last_capture_error' => $payload['last_capture_error'] ?? null,
                'sent_at' => Rfc3339::parse($payload['sent_at'] ?? null),
                'received_at' => $receivedAt,
            ]);

            Device::query()->whereKey($device->id)->update([
                'latest_heartbeat_id' => $heartbeat->id,
                'last_contact_at' => $receivedAt,
                'capabilities' => json_encode($payload['capabilities']),
                'software_version' => $payload['agent_version'],
                // Null while the agent has no acquisition session (e.g. microphone not yet connected).
                'current_boot_id' => isset($payload['boot_id']) ? strtolower($payload['boot_id']) : null,
            ]);
        });

        $device->refresh();

        return [
            'desired_config_revision' => $device->desired_config_revision,
            'applied_config_revision' => $device->applied_config_revision,
            'configuration_pending' => $device->desired_config_revision !== null && $device->desired_config_revision !== ($payload['applied_config_revision'] ?? null),
            'heartbeat_interval_seconds' => $device->heartbeat_interval_seconds,
            'reporting_interval_seconds' => $device->reporting_interval_seconds,
        ];
    }
}
