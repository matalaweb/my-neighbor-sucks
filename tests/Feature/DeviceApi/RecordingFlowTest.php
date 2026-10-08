<?php

use App\Enums\RecordingStatus;
use App\Enums\UploadAttemptState;
use App\Models\EventRecording;
use App\Models\NoiseEvent;
use App\Models\RecordingUploadAttempt;
use App\Services\Recordings\VerifyRecording;
use App\Support\SyntheticAudio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\DeviceFixture;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-08T12:20:00Z');
    Storage::fake('s3');
    $this->fixture = DeviceFixture::create();
    $this->start = CarbonImmutable::parse('2026-10-08T12:15:00Z');
    $this->eventId = (string) Str::uuid();
    $this->devicePost($this->fixture, 'events', $this->fixture->event($this->eventId, 1, $this->start, $this->start->addSeconds(5)))->assertCreated();
    $this->wav = SyntheticAudio::wav(2000);
    $this->recordingId = (string) Str::uuid();
});

function declaration(string $recordingId, string $bytes, array $overrides = []): array
{
    return array_merge([
        'schema_version' => 1,
        'recording_id' => $recordingId,
        'segment_number' => 1,
        'capture_started_at' => '2026-10-08T12:14:50.000Z',
        ...SyntheticAudio::describe($bytes, 2000),
    ], $overrides);
}

function stagingKey(string $attemptId): string
{
    return RecordingUploadAttempt::query()->where('uuid', $attemptId)->value('staging_key');
}

function verifyAll(): void
{
    app(VerifyRecording::class)->processDirty();
}

it('declares, uploads, completes, and verifies a recording in two stages', function (): void {
    $declared = $this->devicePost($this->fixture, 'events/'.$this->eventId.'/recordings', declaration($this->recordingId, $this->wav))
        ->assertCreated()
        ->assertJson(['recording_id' => $this->recordingId, 'status' => 'pending', 'verified' => false])
        ->assertJsonStructure(['upload' => ['attempt_id', 'method', 'url', 'headers', 'expires_at']]);

    $attemptId = $declared->json('upload.attempt_id');
    $key = stagingKey($attemptId);
    expect($key)->toStartWith('staging/'.$this->fixture->account->uuid.'/'.$this->fixture->device->uuid.'/');
    Storage::disk('s3')->put($key, $this->wav);

    $this->deviceGet($this->fixture, 'recordings/'.$this->recordingId)->assertOk()->assertJson(['status' => 'pending', 'retain_local_copy' => true]);

    $this->devicePost($this->fixture, 'recordings/'.$this->recordingId.'/complete', ['schema_version' => 1, 'attempt_id' => $attemptId])
        ->assertStatus(202)->assertJson(['status' => 'uploaded', 'verified' => false, 'retain_local_copy' => true]);
    // Repeated completion is idempotent.
    $this->devicePost($this->fixture, 'recordings/'.$this->recordingId.'/complete', ['schema_version' => 1, 'attempt_id' => $attemptId])
        ->assertStatus(202)->assertJson(['status' => 'uploaded']);
    expect(NoiseEvent::query()->sole()->recording_state)->toBe(RecordingStatus::Verifying);

    verifyAll();
    verifyAll();

    $recording = EventRecording::query()->sole();
    expect($recording->status)->toBe(RecordingStatus::Verified)
        ->and($recording->verified_sha256)->toBe(hash('sha256', $this->wav))
        ->and($recording->final_key)->toStartWith('recordings/')
        ->and(hash('sha256', Storage::disk('s3')->get($recording->final_key)))->toBe(hash('sha256', $this->wav))
        ->and($recording->media_info['duration_ms'])->toBe(2000)
        ->and(Storage::disk('s3')->exists($key))->toBeFalse()
        ->and(NoiseEvent::query()->sole()->recording_state)->toBe(RecordingStatus::Verified);

    $this->deviceGet($this->fixture, 'recordings/'.$this->recordingId)
        ->assertOk()->assertJson(['status' => 'verified', 'verified' => true, 'retain_local_copy' => false, 'verified_sha256' => hash('sha256', $this->wav)]);

    // Completion after verification reports verified and changes nothing.
    $this->devicePost($this->fixture, 'recordings/'.$this->recordingId.'/complete', ['schema_version' => 1, 'attempt_id' => $attemptId])
        ->assertOk()->assertJson(['status' => 'verified']);
    $this->devicePost($this->fixture, 'recordings/'.$this->recordingId.'/upload-attempts', [])
        ->assertStatus(409)->assertJsonPath('error.code', 'recording_already_verified');
});

it('never verifies a checksum mismatch and never rewrites the declaration', function (): void {
    $declared = $this->devicePost($this->fixture, 'events/'.$this->eventId.'/recordings', declaration($this->recordingId, $this->wav))->assertCreated();
    $attemptId = $declared->json('upload.attempt_id');

    $tampered = $this->wav;
    $tampered[100] = chr(ord($tampered[100]) ^ 0xFF);
    Storage::disk('s3')->put(stagingKey($attemptId), $tampered);

    $this->devicePost($this->fixture, 'recordings/'.$this->recordingId.'/complete', ['schema_version' => 1, 'attempt_id' => $attemptId])->assertStatus(202);
    verifyAll();

    $recording = EventRecording::query()->sole();
    $attempt = RecordingUploadAttempt::query()->sole();
    expect($recording->status)->toBe(RecordingStatus::Failed)
        ->and($recording->failure_reason)->toBe('sha256_mismatch')
        ->and($recording->reported_sha256)->toBe(hash('sha256', $this->wav))
        ->and($recording->verified_sha256)->toBeNull()
        ->and($recording->final_key)->toBeNull()
        ->and($attempt->state)->toBe(UploadAttemptState::Failed)
        ->and($attempt->computed_sha256)->toBe(hash('sha256', $tampered))
        ->and(NoiseEvent::query()->sole()->recording_state)->toBe(RecordingStatus::Failed);

    // Explicit retry after failure uses a fresh staging key.
    $retry = $this->devicePost($this->fixture, 'recordings/'.$this->recordingId.'/upload-attempts', [])->assertCreated();
    $newAttempt = $retry->json('upload.attempt_id');
    expect($newAttempt)->not->toBe($attemptId)->and(stagingKey($newAttempt))->not->toBe(stagingKey($attemptId));

    Storage::disk('s3')->put(stagingKey($newAttempt), $this->wav);
    $this->devicePost($this->fixture, 'recordings/'.$this->recordingId.'/complete', ['schema_version' => 1, 'attempt_id' => $newAttempt])->assertStatus(202);
    verifyAll();

    expect(EventRecording::query()->sole()->status)->toBe(RecordingStatus::Verified);
});

it('rejects media whose headers contradict the declaration', function (string $field, mixed $value, string $reason): void {
    $declared = $this->devicePost($this->fixture, 'events/'.$this->eventId.'/recordings', declaration($this->recordingId, $this->wav, [$field => $value]))->assertCreated();
    $attemptId = $declared->json('upload.attempt_id');
    Storage::disk('s3')->put(stagingKey($attemptId), $this->wav);
    $this->devicePost($this->fixture, 'recordings/'.$this->recordingId.'/complete', ['schema_version' => 1, 'attempt_id' => $attemptId])->assertStatus(202);
    verifyAll();

    expect(EventRecording::query()->sole()->failure_reason)->toBe($reason);
})->with([
    'duration' => ['duration_ms', 5000, 'duration_mismatch'],
    'sample rate' => ['sample_rate_hz', 16000, 'sample_rate_mismatch'],
    'size' => ['byte_size', 99999, 'size_mismatch'],
]);

it('replaces expired upload attempts and supersedes older ones', function (): void {
    $first = $this->devicePost($this->fixture, 'events/'.$this->eventId.'/recordings', declaration($this->recordingId, $this->wav))->assertCreated();

    // Re-declaring while the attempt is still valid returns the same attempt.
    $again = $this->devicePost($this->fixture, 'events/'.$this->eventId.'/recordings', declaration($this->recordingId, $this->wav))->assertOk();
    expect($again->json('upload.attempt_id'))->toBe($first->json('upload.attempt_id'));

    CarbonImmutable::setTestNow('2026-10-08T13:00:00Z');
    $fresh = $this->devicePost($this->fixture, 'recordings/'.$this->recordingId.'/upload-attempts', [])->assertCreated();
    expect($fresh->json('upload.attempt_id'))->not->toBe($first->json('upload.attempt_id'))
        ->and(RecordingUploadAttempt::query()->where('uuid', $first->json('upload.attempt_id'))->value('state'))->toBe(UploadAttemptState::Superseded);

    $this->devicePost($this->fixture, 'recordings/'.$this->recordingId.'/complete', ['schema_version' => 1, 'attempt_id' => $first->json('upload.attempt_id')])
        ->assertStatus(409)->assertJsonPath('error.code', 'upload_attempt_mismatch');
});

it('rejects a changed declaration and conflicting segment numbers', function (): void {
    $this->devicePost($this->fixture, 'events/'.$this->eventId.'/recordings', declaration($this->recordingId, $this->wav))->assertCreated();

    $this->devicePost($this->fixture, 'events/'.$this->eventId.'/recordings', declaration($this->recordingId, $this->wav, ['duration_ms' => 1999]))
        ->assertStatus(409)->assertJsonPath('error.code', 'recording_conflict');

    $this->devicePost($this->fixture, 'events/'.$this->eventId.'/recordings', declaration((string) Str::uuid(), $this->wav))
        ->assertStatus(409)->assertJsonPath('error.code', 'recording_conflict');

    $this->devicePost($this->fixture, 'events/'.$this->eventId.'/recordings', declaration((string) Str::uuid(), $this->wav, ['segment_number' => 2, 'channel_count' => 2]))
        ->assertStatus(422);
});

it('attaches a late recording to its event and keeps the event visible meanwhile', function (): void {
    expect(NoiseEvent::query()->sole()->recording_state)->toBe(RecordingStatus::Pending);

    CarbonImmutable::setTestNow('2026-10-08T18:00:00Z');
    $declared = $this->devicePost($this->fixture, 'events/'.$this->eventId.'/recordings', declaration($this->recordingId, $this->wav))->assertCreated();
    Storage::disk('s3')->put(stagingKey($declared->json('upload.attempt_id')), $this->wav);
    $this->devicePost($this->fixture, 'recordings/'.$this->recordingId.'/complete', ['schema_version' => 1, 'attempt_id' => $declared->json('upload.attempt_id')])->assertStatus(202);
    verifyAll();

    $event = NoiseEvent::query()->sole();
    expect($event->recordings()->sole()->uuid)->toBe($this->recordingId)
        ->and($event->recording_state)->toBe(RecordingStatus::Verified);
});

it('refuses recordings when the account policy disables them', function (): void {
    $this->fixture->account->forceFill(['settings' => ['recording' => ['enabled' => false]]])->save();

    $this->devicePost($this->fixture, 'events/'.$this->eventId.'/recordings', declaration($this->recordingId, $this->wav))
        ->assertStatus(422)->assertJsonPath('error.code', 'recordings_disabled');
});
