<?php

use App\Enums\RecordingStatus;
use App\Models\EventRecording;
use App\Models\RecordingUploadAttempt;
use App\Services\Recordings\VerifyRecording;
use App\Services\Storage\EvidenceStorage;
use App\Support\SyntheticAudio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\DeviceFixture;

/*
| Real S3-compatible integration (local RustFS, bucket noise-monitor-test):
| presigned upload signing, server-side hashing/finalization, and HTTP Range
| downloads. Skipped when the bucket is unreachable.
*/

beforeEach(function (): void {
    config(['noise.storage.browser_endpoint' => null, 'noise.storage.device_endpoint' => null]);
    app()->forgetInstance(EvidenceStorage::class);

    try {
        app(EvidenceStorage::class)->disk()->put('integration/ping.txt', 'ok');
    } catch (Throwable $exception) {
        $this->markTestSkipped('S3-compatible storage unavailable: '.$exception->getMessage());
    }

    if (! Schema::hasTable('measurements')) {
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    $this->fixture = DeviceFixture::create();
    $this->eventId = (string) Str::uuid();
    $start = CarbonImmutable::now()->subMinutes(2)->startOfSecond();
    $this->devicePost($this->fixture, 'events', $this->fixture->event($this->eventId, 1, $start, $start->addSeconds(5)))->assertCreated();
    $this->wav = SyntheticAudio::wav(1500, 8000, 'vehicle', 7);
    $this->recordingId = (string) Str::uuid();
});

afterEach(function (): void {
    $disk = app(EvidenceStorage::class)->disk();

    foreach (EventRecording::query()->whereNotNull('final_key')->pluck('final_key') as $key) {
        $disk->delete($key);
    }

    foreach (RecordingUploadAttempt::query()->pluck('staging_key') as $key) {
        $disk->delete($key);
    }

    DB::statement('SET FOREIGN_KEY_CHECKS=0');

    foreach (DB::select('SHOW TABLES') as $table) {
        $name = array_values((array) $table)[0];

        if ($name !== 'migrations') {
            DB::table($name)->truncate();
        }
    }

    DB::statement('SET FOREIGN_KEY_CHECKS=1');
});

function declareRecording($test, string $bytes): array
{
    return $test->devicePost($test->fixture, 'events/'.$test->eventId.'/recordings', [
        'schema_version' => 1,
        'recording_id' => $test->recordingId,
        'segment_number' => 1,
        'capture_started_at' => CarbonImmutable::now()->subMinutes(2)->format('Y-m-d\TH:i:s.v\Z'),
        ...SyntheticAudio::describe($bytes, 1500),
    ])->assertCreated()->json('upload');
}

function putPresigned(array $upload, string $bytes): int
{
    return Http::withHeaders((array) $upload['headers'])->withBody($bytes, 'audio/wav')->send('PUT', $upload['url'])->status();
}

it('signs uploads, hashes the uploaded bytes, and finalizes into a server-owned object', function (): void {
    $upload = declareRecording($this, $this->wav);

    expect($upload['method'])->toBe('PUT')
        ->and($upload['url'])->toContain('X-Amz-Signature=')
        ->and(putPresigned($upload, $this->wav))->toBe(200);

    $this->devicePost($this->fixture, 'recordings/'.$this->recordingId.'/complete', ['schema_version' => 1, 'attempt_id' => $upload['attempt_id']])->assertStatus(202);
    app(VerifyRecording::class)->processDirty();

    $recording = EventRecording::query()->sole();
    $disk = app(EvidenceStorage::class)->disk();

    expect($recording->status)->toBe(RecordingStatus::Verified)
        ->and($recording->verified_sha256)->toBe(hash('sha256', $this->wav))
        ->and(hash('sha256', $disk->get($recording->final_key)))->toBe(hash('sha256', $this->wav))
        ->and($disk->exists(RecordingUploadAttempt::query()->sole()->staging_key))->toBeFalse();

    // A device re-using the still-valid staging URL cannot change the verified original.
    $tampered = SyntheticAudio::wav(1500, 8000, 'garage', 99);
    expect(putPresigned($upload, $tampered))->toBe(200);
    app(VerifyRecording::class)->verify(RecordingUploadAttempt::query()->sole());

    $recording->refresh();
    expect($recording->status)->toBe(RecordingStatus::Verified)
        ->and($recording->verified_sha256)->toBe(hash('sha256', $this->wav))
        ->and(hash('sha256', $disk->get($recording->final_key)))->toBe(hash('sha256', $this->wav));
});

it('fails verification when the uploaded bytes do not match the declared hash', function (): void {
    $upload = declareRecording($this, $this->wav);
    putPresigned($upload, SyntheticAudio::wav(1500, 8000, 'garage', 3));

    $this->devicePost($this->fixture, 'recordings/'.$this->recordingId.'/complete', ['schema_version' => 1, 'attempt_id' => $upload['attempt_id']])->assertStatus(202);
    app(VerifyRecording::class)->processDirty();

    $recording = EventRecording::query()->sole();
    expect($recording->status)->toBe(RecordingStatus::Failed)
        ->and($recording->final_key)->toBeNull()
        ->and($recording->reported_sha256)->toBe(hash('sha256', $this->wav));

    $this->deviceGet($this->fixture, 'recordings/'.$this->recordingId)->assertJson(['status' => 'failed', 'retain_local_copy' => true]);
});

it('serves short-lived range-capable download URLs for the preserved original', function (): void {
    $upload = declareRecording($this, $this->wav);
    putPresigned($upload, $this->wav);
    $this->devicePost($this->fixture, 'recordings/'.$this->recordingId.'/complete', ['schema_version' => 1, 'attempt_id' => $upload['attempt_id']]);
    app(VerifyRecording::class)->processDirty();

    $recording = EventRecording::query()->sole();
    $url = app(EvidenceStorage::class)->browserUrl($recording->final_key, CarbonImmutable::now()->addMinutes(5), 'clip.wav', 'audio/wav');

    $response = Http::withHeaders(['Range' => 'bytes=0-43'])->get($url);

    expect($response->status())->toBe(206)
        ->and($response->body())->toBe(substr($this->wav, 0, 44))
        ->and($response->header('Content-Range'))->toBe('bytes 0-43/'.strlen($this->wav))
        ->and($url)->toContain('X-Amz-Expires=300');
});
