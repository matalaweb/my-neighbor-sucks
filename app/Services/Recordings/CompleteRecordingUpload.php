<?php

namespace App\Services\Recordings;

use App\Enums\MaintenanceJobKind;
use App\Enums\RecordingStatus;
use App\Enums\UploadAttemptState;
use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\ErrorCode;
use App\Jobs\VerifyRecordings;
use App\Models\Device;
use App\Models\EventRecording;
use App\Models\RecordingUploadAttempt;
use App\Services\Events\RecordingStateProjector;
use App\Services\Maintenance\MaintenanceQueue;
use App\Support\Rfc3339;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stage one of completion: record the exact upload attempt and return
 * pending verification. Stage two (VerifyRecording) runs on a worker. The
 * agent must keep its local clip until status reports "verified".
 */
class CompleteRecordingUpload
{
    public function __construct(
        private readonly IssueRecordingUpload $issuer,
        private readonly MaintenanceQueue $maintenance,
        private readonly RecordingStateProjector $recordingState,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(Device $device, string $recordingUuid, array $payload, CarbonImmutable $now): array
    {
        $recording = $this->issuer->recordingFor($device, $recordingUuid);

        if (($payload['schema_version'] ?? null) !== (int) config('noise.device_api.schema_version')) {
            throw new DeviceApiException(ErrorCode::UnsupportedSchemaVersion, 'schema_version must be '.config('noise.device_api.schema_version').'.');
        }

        $unknown = array_diff(array_keys($payload), ['schema_version', 'attempt_id']);
        $attemptUuid = $payload['attempt_id'] ?? null;

        if ($unknown !== [] || ! is_string($attemptUuid)) {
            throw new DeviceApiException(ErrorCode::ValidationFailed, 'Completion requires exactly schema_version and attempt_id.', [
                'errors' => array_merge(
                    array_fill_keys($unknown, ['Unknown field.']),
                    is_string($attemptUuid) ? [] : ['attempt_id' => ['Required UUID of the upload attempt.']],
                ),
            ]);
        }

        return DB::transaction(function () use ($recording, $attemptUuid, $now): array {
            $recording = EventRecording::query()->whereKey($recording->id)->lockForUpdate()->first();
            $attempt = RecordingUploadAttempt::query()
                ->where('event_recording_id', $recording->id)
                ->where('uuid', strtolower($attemptUuid))
                ->lockForUpdate()
                ->first();

            if ($attempt === null) {
                throw new DeviceApiException(ErrorCode::UploadAttemptMismatch, 'attempt_id does not belong to this recording.');
            }

            if ($recording->status === RecordingStatus::Verified || $recording->status === RecordingStatus::Purged) {
                return ['status' => 200, 'body' => $this->statusBody($recording, $attempt)];
            }

            if ($attempt->state === UploadAttemptState::Issued) {
                if ($attempt->expires_at->addHours((int) config('noise.recordings.staging_retention_hours'))->lessThan($now)) {
                    throw new DeviceApiException(ErrorCode::UploadAttemptMismatch, 'This upload attempt is too old; request a new upload attempt.');
                }

                $attempt->forceFill(['state' => UploadAttemptState::Completed, 'completed_at' => $now])->save();
                $recording->forceFill(['status' => RecordingStatus::Uploaded, 'failure_reason' => null])->save();
                $this->maintenance->mark(MaintenanceJobKind::VerifyRecording, (string) $attempt->id, $recording->account_id, 'recording_upload_attempt', $attempt->id);
                $this->recordingState->refresh($recording->noiseEvent);
                VerifyRecordings::dispatch()->afterCommit();
            } elseif (in_array($attempt->state, [UploadAttemptState::Superseded, UploadAttemptState::Expired, UploadAttemptState::Cleaned], true)) {
                throw new DeviceApiException(ErrorCode::UploadAttemptMismatch, 'This upload attempt was superseded; complete the latest attempt.', [
                    'attempt_state' => $attempt->state->value,
                ]);
            }

            // Completed/verifying/failed: idempotent report of the current state.
            return ['status' => $attempt->state === UploadAttemptState::Failed ? 200 : 202, 'body' => $this->statusBody($recording, $attempt)];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function statusBody(EventRecording $recording, ?RecordingUploadAttempt $attempt = null): array
    {
        $attempt ??= $recording->uploadAttempts()->reorder('id', 'desc')->first();

        return [
            'recording_id' => $recording->uuid,
            'event_id' => $recording->noiseEvent->uuid,
            'segment_number' => $recording->segment_number,
            'status' => $recording->status->value,
            'verified' => $recording->status === RecordingStatus::Verified,
            'verified_sha256' => $recording->verified_sha256,
            'verified_at' => Rfc3339::format($recording->verified_at),
            'failure_reason' => $recording->failure_reason,
            'retain_local_copy' => $recording->status !== RecordingStatus::Verified && $recording->status !== RecordingStatus::Purged,
            'latest_attempt' => $attempt === null ? null : [
                'attempt_id' => $attempt->uuid,
                'state' => $attempt->state->value,
                'expires_at' => Rfc3339::format($attempt->expires_at),
                'failure_reason' => $attempt->failure_reason,
            ],
        ];
    }
}
