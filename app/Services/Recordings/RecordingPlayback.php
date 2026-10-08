<?php

namespace App\Services\Recordings;

use App\Models\EventRecording;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Storage\EvidenceStorage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/**
 * Issues short-lived, authorized URLs for verified originals (HTTP Range is
 * supported by the object store). URLs are never embedded in reports.
 */
class RecordingPlayback
{
    public function __construct(
        private readonly EvidenceStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function playbackUrl(User $user, EventRecording $recording): string
    {
        if (! $user->isMemberOf($recording->account_id)) {
            throw new AuthorizationException('Not permitted.');
        }

        if (! $recording->isPlayable()) {
            throw new RuntimeException('This recording is not available for playback ('.$recording->status->getLabel().').');
        }

        $this->audit->record('recording.playback_url_issued', $recording, [], user: $user);

        return $this->storage->browserUrl(
            $recording->final_key,
            CarbonImmutable::now()->addMinutes((int) config('noise.recordings.playback_url_ttl_minutes')),
            contentType: $recording->mime_type,
        );
    }

    public function downloadUrl(User $user, EventRecording $recording): string
    {
        if (! $user->canExport($recording->account_id)) {
            throw new AuthorizationException('Viewers cannot download originals.');
        }

        if (! $recording->isPlayable()) {
            throw new RuntimeException('This recording is not available.');
        }

        $this->audit->record('recording.download_url_issued', $recording, ['sha256' => $recording->verified_sha256], user: $user);

        return $this->storage->browserUrl(
            $recording->final_key,
            CarbonImmutable::now()->addMinutes((int) config('noise.recordings.playback_url_ttl_minutes')),
            sprintf('event-%s-segment-%d-original.%s', $recording->noiseEvent->uuid, $recording->segment_number, $recording->fileExtension()),
            $recording->mime_type,
        );
    }
}
