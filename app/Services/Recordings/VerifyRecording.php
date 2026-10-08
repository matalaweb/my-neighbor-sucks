<?php

namespace App\Services\Recordings;

use App\Enums\MaintenanceJobKind;
use App\Enums\RecordingStatus;
use App\Enums\UploadAttemptState;
use App\Models\EventRecording;
use App\Models\MaintenanceJob;
use App\Models\RecordingUploadAttempt;
use App\Services\Audit\AuditLogger;
use App\Services\Events\RecordingStateProjector;
use App\Services\Maintenance\MaintenanceQueue;
use App\Services\Maintenance\RetryLater;
use App\Services\Storage\EvidenceStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stage two of completion (spec §10). Streams the staging object once into a
 * local temporary file while hashing those same bytes, validates length,
 * SHA-256, and media headers against the declaration, then writes exactly
 * those bytes to a new server-owned final key. The final key and verification
 * result are committed together. A staging object replaced after
 * verification can never change the preserved original, and S3 ETags are
 * never treated as SHA-256.
 */
class VerifyRecording
{
    public function __construct(
        private readonly EvidenceStorage $storage,
        private readonly MediaInspector $inspector,
        private readonly MaintenanceQueue $maintenance,
        private readonly RecordingStateProjector $recordingState,
        private readonly AuditLogger $audit,
    ) {}

    public function processDirty(int $limit = 20): int
    {
        return $this->maintenance->work(
            [MaintenanceJobKind::VerifyRecording],
            function (MaintenanceJob $job): void {
                $attempt = RecordingUploadAttempt::query()->find($job->subject_id);

                if ($attempt !== null) {
                    $this->verify($attempt);
                }
            },
            $limit,
            maxAttempts: 8,
        );
    }

    public function verify(RecordingUploadAttempt $attempt): void
    {
        $claimed = DB::transaction(function () use ($attempt): bool {
            $recording = EventRecording::query()->whereKey($attempt->event_recording_id)->lockForUpdate()->first();
            $attempt = RecordingUploadAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();

            if ($recording->status === RecordingStatus::Verified || $recording->status === RecordingStatus::Purged) {
                if (in_array($attempt->state, [UploadAttemptState::Completed, UploadAttemptState::Verifying], true)) {
                    $attempt->forceFill(['state' => UploadAttemptState::Superseded, 'finished_at' => CarbonImmutable::now()])->save();
                }

                return false;
            }

            if (! in_array($attempt->state, [UploadAttemptState::Completed, UploadAttemptState::Verifying], true)) {
                return false;
            }

            $attempt->forceFill(['state' => UploadAttemptState::Verifying, 'verification_started_at' => CarbonImmutable::now()])->save();
            $recording->forceFill(['status' => RecordingStatus::Verifying])->save();

            return true;
        });

        if (! $claimed) {
            return;
        }

        $attempt->refresh();
        $recording = $attempt->recording;
        $disk = $this->storage->disk();

        if (! $disk->exists($attempt->staging_key)) {
            if ($attempt->completed_at !== null && $attempt->completed_at->addMinutes(10)->isFuture()) {
                throw new RetryLater(30, 'Staging object not visible yet.');
            }

            $this->fail($attempt, 'object_missing', 'No object was found at the staging key.');

            return;
        }

        $download = $this->storage->downloadAndHash($attempt->staging_key);

        try {
            $media = $this->inspector->inspect($download['path']);
            $problem = $this->compare($recording, $download, $media);

            if ($problem !== null) {
                $this->fail($attempt, $problem[0], $problem[1], $download);

                return;
            }

            $finalKey = sprintf(
                'recordings/%s/%s/%s/%s/%s.%s',
                $recording->device->account->uuid,
                $recording->device->uuid,
                $recording->noiseEvent->uuid,
                $recording->uuid,
                $attempt->uuid,
                $recording->fileExtension(),
            );

            $stream = fopen($download['path'], 'rb');

            try {
                $disk->writeStream($finalKey, $stream, ['ContentType' => $recording->mime_type]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ((int) $disk->size($finalKey) !== $download['bytes']) {
                throw new \RuntimeException('Final object size does not match verified bytes.');
            }

            $this->commit($attempt, $finalKey, $download, $media);
        } finally {
            @unlink($download['path']);
        }
    }

    /**
     * @param  array{path: string, sha256: string, bytes: int}  $download
     * @param  array<string, mixed>|null  $media
     * @return array{0: string, 1: string}|null
     */
    private function compare(EventRecording $recording, array $download, ?array $media): ?array
    {
        if ($download['bytes'] !== (int) $recording->byte_size) {
            return ['size_mismatch', "Uploaded {$download['bytes']} bytes; declaration says {$recording->byte_size}."];
        }

        if (! hash_equals($recording->reported_sha256, $download['sha256'])) {
            return ['sha256_mismatch', 'Independently calculated SHA-256 does not match the declared SHA-256.'];
        }

        if ($media === null) {
            return ['unreadable_media', 'The object is not a readable WAV or FLAC file.'];
        }

        $expectedContainer = $recording->mime_type === 'audio/flac' ? 'flac' : 'wav';
        $tolerance = (int) config('noise.recordings.duration_tolerance_ms');

        return match (true) {
            $media['container'] !== $expectedContainer => ['format_mismatch', "Container is {$media['container']}; declared {$recording->mime_type}."],
            $media['codec'] !== $recording->codec => ['codec_mismatch', "Codec is {$media['codec']}; declared {$recording->codec}."],
            $media['sample_rate_hz'] !== (int) $recording->sample_rate_hz => ['sample_rate_mismatch', "Sample rate is {$media['sample_rate_hz']} Hz; declared {$recording->sample_rate_hz} Hz."],
            $media['channel_count'] !== (int) $recording->channel_count => ['channel_count_mismatch', "Channel count is {$media['channel_count']}; declared {$recording->channel_count}."],
            $recording->bit_depth !== null && $media['bit_depth'] !== (int) $recording->bit_depth => ['bit_depth_mismatch', "Bit depth is {$media['bit_depth']}; declared {$recording->bit_depth}."],
            abs($media['duration_ms'] - (int) $recording->duration_ms) > $tolerance => ['duration_mismatch', "Duration is {$media['duration_ms']} ms; declared {$recording->duration_ms} ms."],
            default => null,
        };
    }

    /**
     * @param  array{path: string, sha256: string, bytes: int}  $download
     * @param  array<string, mixed>  $media
     */
    private function commit(RecordingUploadAttempt $attempt, string $finalKey, array $download, array $media): void
    {
        $now = CarbonImmutable::now();

        $verified = DB::transaction(function () use ($attempt, $finalKey, $download, $media, $now): bool {
            $recording = EventRecording::query()->whereKey($attempt->event_recording_id)->lockForUpdate()->first();
            $lockedAttempt = RecordingUploadAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();

            if ($recording->status === RecordingStatus::Verified || $recording->status === RecordingStatus::Purged) {
                $lockedAttempt->forceFill(['state' => UploadAttemptState::Superseded, 'finished_at' => $now])->save();

                return false;
            }

            $recording->forceFill([
                'status' => RecordingStatus::Verified,
                'failure_reason' => null,
                'verified_sha256' => $download['sha256'],
                'verified_byte_size' => $download['bytes'],
                'verified_duration_ms' => $media['duration_ms'],
                'media_info' => $media,
                'final_disk' => $this->storage->diskName(),
                'final_key' => $finalKey,
                'verified_at' => $now,
            ])->save();

            $lockedAttempt->forceFill([
                'state' => UploadAttemptState::Verified,
                'computed_sha256' => $download['sha256'],
                'computed_byte_size' => $download['bytes'],
                'finished_at' => $now,
            ])->save();

            $this->recordingState->refresh($recording->noiseEvent);

            $this->audit->record('recording.verified', $recording, [
                'sha256' => $download['sha256'],
                'byte_size' => $download['bytes'],
                'attempt_id' => $lockedAttempt->uuid,
            ], device: $recording->device);

            return true;
        });

        if (! $verified) {
            // Another attempt won; our copy is unreferenced.
            $this->storage->disk()->delete($finalKey);
        }

        $this->deleteStaging($attempt);
    }

    /**
     * @param  array{path: string, sha256: string, bytes: int}|null  $download
     */
    private function fail(RecordingUploadAttempt $attempt, string $reason, string $detail, ?array $download = null): void
    {
        DB::transaction(function () use ($attempt, $reason, $detail, $download): void {
            $recording = EventRecording::query()->whereKey($attempt->event_recording_id)->lockForUpdate()->first();
            $lockedAttempt = RecordingUploadAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();

            $lockedAttempt->forceFill([
                'state' => UploadAttemptState::Failed,
                'failure_reason' => $reason.': '.$detail,
                'computed_sha256' => $download['sha256'] ?? null,
                'computed_byte_size' => $download['bytes'] ?? null,
                'finished_at' => CarbonImmutable::now(),
            ])->save();

            // The declaration (reported hash, size) is never rewritten to make a mismatch pass.
            if ($recording->status !== RecordingStatus::Verified && $recording->status !== RecordingStatus::Purged) {
                $recording->forceFill(['status' => RecordingStatus::Failed, 'failure_reason' => $reason])->save();
                $this->recordingState->refresh($recording->noiseEvent);
            }

            $this->audit->record('recording.verification_failed', $recording, [
                'reason' => $reason,
                'detail' => $detail,
                'attempt_id' => $lockedAttempt->uuid,
                'computed_sha256' => $download['sha256'] ?? null,
            ], device: $recording->device);
        });
    }

    private function deleteStaging(RecordingUploadAttempt $attempt): void
    {
        try {
            $this->storage->disk()->delete($attempt->staging_key);
            RecordingUploadAttempt::query()->whereKey($attempt->id)->update(['staging_deleted_at' => CarbonImmutable::now()]);
        } catch (\Throwable $exception) {
            // The staging cleanup job retries abandoned objects.
            report($exception);
        }
    }
}
