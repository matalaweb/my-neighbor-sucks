<?php

namespace App\Services\Recordings;

use App\Enums\RecordingStatus;
use App\Enums\UploadAttemptState;
use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\ErrorCode;
use App\Http\DeviceApi\SchemaVersion;
use App\Models\Device;
use App\Models\EventRecording;
use App\Models\NoiseEvent;
use App\Models\RecordingUploadAttempt;
use App\Services\Events\RecordingStateProjector;
use App\Services\Storage\EvidenceStorage;
use App\Support\CanonicalJson;
use App\Support\Rfc3339;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Accepts an immutable recording declaration and issues short-lived
 * presigned uploads to server-generated staging keys (spec §10). The device
 * never chooses buckets, prefixes, ACLs, or final evidence keys.
 */
class IssueRecordingUpload
{
    public function __construct(
        private readonly EvidenceStorage $storage,
        private readonly RecordingStateProjector $recordingState,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function declare(Device $device, string $eventUuid, array $payload, CarbonImmutable $now): array
    {
        $event = NoiseEvent::query()->where('device_id', $device->id)->where('uuid', strtolower($eventUuid))->first();

        if ($event === null) {
            throw new DeviceApiException(ErrorCode::NotFound, 'Unknown event for this device; submit the event before its recording.');
        }

        if (! $event->account->recordingsEnabled()) {
            throw new DeviceApiException(ErrorCode::RecordingsDisabled, 'Event recordings are disabled for this account.');
        }

        SchemaVersion::assert($payload);
        $declaration = $this->validate($payload);
        $declarationHash = CanonicalJson::hash($declaration);

        $existing = EventRecording::query()->where('device_id', $device->id)->where('uuid', $declaration['recording_id'])->first();

        if ($existing === null) {
            try {
                $recording = DB::transaction(function () use ($device, $event, $declaration, $declarationHash, $now): EventRecording {
                    $recording = EventRecording::query()->create([
                        'uuid' => $declaration['recording_id'],
                        'account_id' => $device->account_id,
                        'device_id' => $device->id,
                        'noise_event_id' => $event->id,
                        'segment_number' => $declaration['segment_number'],
                        'capture_started_at' => Rfc3339::parse($declaration['capture_started_at']),
                        'duration_ms' => $declaration['duration_ms'],
                        'mime_type' => $declaration['mime_type'],
                        'codec' => $declaration['codec'],
                        'sample_rate_hz' => $declaration['sample_rate_hz'],
                        'channel_count' => $declaration['channel_count'],
                        'bit_depth' => $declaration['bit_depth'],
                        'byte_size' => $declaration['byte_size'],
                        'reported_sha256' => $declaration['sha256'],
                        'declaration_hash' => $declarationHash,
                        'status' => RecordingStatus::Pending,
                        'declared_at' => $now,
                    ]);

                    $this->recordingState->refresh($event);

                    return $recording;
                });
            } catch (UniqueConstraintViolationException) {
                $existing = EventRecording::query()->where('device_id', $device->id)->where('uuid', $declaration['recording_id'])->first();

                if ($existing === null) {
                    throw new DeviceApiException(ErrorCode::RecordingConflict, 'Segment '.$declaration['segment_number'].' of this event is already declared by a different recording.');
                }

                return $this->redeclare($existing, $event, $declarationHash, $now);
            }

            return ['status' => 201, 'body' => $this->body($recording, $this->newAttempt($recording, $now))];
        }

        return $this->redeclare($existing, $event, $declarationHash, $now);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function redeclare(EventRecording $existing, NoiseEvent $event, string $declarationHash, CarbonImmutable $now): array
    {
        if ($existing->noise_event_id !== $event->id || ! hash_equals($existing->declaration_hash, $declarationHash)) {
            throw new DeviceApiException(ErrorCode::RecordingConflict, 'This recording_id was already declared with a different declaration.');
        }

        if (in_array($existing->status, [RecordingStatus::Verified, RecordingStatus::Purged], true)) {
            return ['status' => 200, 'body' => $this->body($existing, null)];
        }

        $active = $existing->uploadAttempts()
            ->where('state', UploadAttemptState::Issued)
            ->where('expires_at', '>', $now->addMinute())
            ->latest('id')
            ->first();

        return ['status' => 200, 'body' => $this->body($existing, $active ? $this->signed($existing, $active) : $this->newAttempt($existing, $now))];
    }

    /**
     * Fresh attempt with a new staging key (expired upload or explicit retry after failure).
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function reissue(Device $device, string $recordingUuid, CarbonImmutable $now): array
    {
        $recording = $this->recordingFor($device, $recordingUuid);

        if ($recording->status === RecordingStatus::Verified) {
            throw new DeviceApiException(ErrorCode::RecordingAlreadyVerified, 'This recording is already verified; no further uploads are accepted.', [
                'status' => $recording->status->value,
            ]);
        }

        if ($recording->status === RecordingStatus::Purged) {
            throw new DeviceApiException(ErrorCode::RecordingConflict, 'This recording was purged by retention.');
        }

        return ['status' => 201, 'body' => $this->body($recording, $this->newAttempt($recording, $now))];
    }

    public function recordingFor(Device $device, string $recordingUuid): EventRecording
    {
        $recording = EventRecording::query()->where('device_id', $device->id)->where('uuid', strtolower($recordingUuid))->first();

        if ($recording === null) {
            throw new DeviceApiException(ErrorCode::NotFound, 'Unknown recording for this device.');
        }

        return $recording;
    }

    /**
     * @return array<string, mixed>
     */
    private function newAttempt(EventRecording $recording, CarbonImmutable $now): array
    {
        $attempt = DB::transaction(function () use ($recording, $now): RecordingUploadAttempt {
            $recording->uploadAttempts()->where('state', UploadAttemptState::Issued)->update(['state' => UploadAttemptState::Superseded]);

            $uuid = (string) Str::uuid7();
            $device = $recording->device;

            return $recording->uploadAttempts()->create([
                'uuid' => $uuid,
                'account_id' => $recording->account_id,
                'staging_key' => sprintf(
                    'staging/%s/%s/%s/%s.%s',
                    $device->account->uuid,
                    $device->uuid,
                    $recording->uuid,
                    $uuid,
                    $recording->fileExtension(),
                ),
                'expires_at' => $now->addMinutes((int) config('noise.recordings.upload_url_ttl_minutes')),
                'state' => UploadAttemptState::Issued,
            ]);
        });

        return $this->signed($recording, $attempt);
    }

    /**
     * @return array<string, mixed>
     */
    private function signed(EventRecording $recording, RecordingUploadAttempt $attempt): array
    {
        $signed = $this->storage->deviceUploadUrl($attempt->staging_key, $attempt->expires_at, $recording->mime_type);

        return [
            'attempt_id' => $attempt->uuid,
            'method' => $signed['method'],
            'url' => $signed['url'],
            'headers' => (object) $signed['headers'],
            'expires_at' => Rfc3339::format($attempt->expires_at),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $upload
     * @return array<string, mixed>
     */
    public function body(EventRecording $recording, ?array $upload): array
    {
        return array_filter([
            'recording_id' => $recording->uuid,
            'event_id' => $recording->noiseEvent->uuid,
            'segment_number' => $recording->segment_number,
            'status' => $recording->status->value,
            'verified' => $recording->status === RecordingStatus::Verified,
            'upload' => $upload,
        ], fn ($value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validate(array $payload): array
    {
        $allowed = ['schema_version', 'recording_id', 'segment_number', 'capture_started_at', 'duration_ms', 'mime_type', 'codec', 'sample_rate_hz', 'channel_count', 'bit_depth', 'byte_size', 'sha256'];
        $errors = [];

        foreach (array_diff(array_keys($payload), $allowed) as $unknown) {
            $errors[$unknown][] = 'Unknown field.';
        }

        $validator = Validator::make($payload, [
            'schema_version' => ['required', 'integer', 'in:'.config('noise.device_api.schema_version')],
            'recording_id' => ['required', 'uuid'],
            'segment_number' => ['required', 'integer', 'min:1', 'max:1000'],
            'capture_started_at' => ['required', 'string'],
            'duration_ms' => ['required', 'integer', 'min:1', 'max:'.config('noise.recordings.max_duration_ms')],
            'mime_type' => ['required', 'string', 'in:'.implode(',', config('noise.recordings.mime_types'))],
            'codec' => ['required', 'string', 'in:pcm_s16le,pcm_s24le,pcm_s32le,pcm_f32le,flac'],
            'sample_rate_hz' => ['required', 'integer', 'min:8000', 'max:384000'],
            'channel_count' => ['required', 'integer', 'in:1'],
            'bit_depth' => ['nullable', 'integer', 'in:16,24,32'],
            'byte_size' => ['required', 'integer', 'min:44', 'max:'.config('noise.recordings.max_bytes')],
            'sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ], [
            'channel_count.in' => 'Initial formats are mono only.',
            'byte_size.max' => 'Segments may be at most :max bytes; split longer events into sequential segments.',
            'duration_ms.max' => 'Segments may be at most :max ms; split longer events into sequential segments.',
        ]);

        $errors = array_merge_recursive($errors, $validator->errors()->toArray());

        if (! isset($errors['capture_started_at']) && Rfc3339::parse($payload['capture_started_at'] ?? null) === null) {
            $errors['capture_started_at'][] = 'Must be an RFC 3339 timestamp.';
        }

        $mime = $payload['mime_type'] ?? null;
        $codec = $payload['codec'] ?? null;

        if (($mime === 'audio/flac') !== ($codec === 'flac') && ! isset($errors['codec'])) {
            $errors['codec'][] = 'Codec does not match MIME type.';
        }

        if ($errors !== []) {
            throw new DeviceApiException(ErrorCode::ValidationFailed, 'The recording declaration failed validation.', ['errors' => $errors]);
        }

        return [
            'recording_id' => strtolower($payload['recording_id']),
            'segment_number' => $payload['segment_number'],
            'capture_started_at' => Rfc3339::formatMicro(Rfc3339::parse($payload['capture_started_at'])),
            'duration_ms' => $payload['duration_ms'],
            'mime_type' => $mime === 'audio/x-wav' ? 'audio/wav' : $mime,
            'codec' => $codec,
            'sample_rate_hz' => $payload['sample_rate_hz'],
            'channel_count' => $payload['channel_count'],
            'bit_depth' => $payload['bit_depth'] ?? null,
            'byte_size' => $payload['byte_size'],
            'sha256' => $payload['sha256'],
        ];
    }
}
