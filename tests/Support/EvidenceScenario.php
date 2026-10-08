<?php

namespace Tests\Support;

use App\Enums\RecordingStatus;
use App\Enums\ReviewStatus;
use App\Enums\SourceCertainty;
use App\Enums\SourceLabel;
use App\Models\EventAnnotation;
use App\Models\EventRecording;
use App\Models\NoiseEvent;
use App\Services\Events\IngestEventRevision;
use App\Services\Events\SnapshotEventMeasurements;
use App\Services\Ingestion\IngestMeasurements;
use App\Services\Measurements\RebuildRollups;
use App\Services\Storage\EvidenceStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Builds a reviewed event with one-second readings, a frozen snapshot, and
 * a verified recording, through the same services the device API uses.
 */
final class EvidenceScenario
{
    public static function wav(int $seconds = 1, int $sampleRate = 8000): string
    {
        $samples = '';

        for ($i = 0; $i < $seconds * $sampleRate; $i++) {
            $samples .= pack('v', (int) (8000 * sin($i / 5)) & 0xFFFF);
        }

        $dataSize = strlen($samples);

        return 'RIFF'.pack('V', 36 + $dataSize).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16)
            .'data'.pack('V', $dataSize).$samples;
    }

    /**
     * Ingest readings covering [start, start + seconds).
     */
    public static function measurements(DeviceFixture $fixture, CarbonImmutable $start, int $seconds, int $firstSequence = 1, ?callable $tweak = null): void
    {
        $ingest = app(IngestMeasurements::class);

        foreach (array_chunk($fixture->records($seconds, $start, $firstSequence, $tweak), 300) as $chunk) {
            $ingest->handle($fixture->device->fresh(), $fixture->batch($chunk), CarbonImmutable::now());
        }

        app(RebuildRollups::class)->processDirty(5000);
    }

    public static function event(DeviceFixture $fixture, CarbonImmutable $start, int $durationSeconds, ?string $notes = null): NoiseEvent
    {
        $uuid = (string) Str::uuid();
        app(IngestEventRevision::class)->handle($fixture->device->fresh(), $fixture->event($uuid, 1, $start, $start->addSeconds($durationSeconds)), CarbonImmutable::now());
        $event = NoiseEvent::query()->where('uuid', $uuid)->firstOrFail();
        app(SnapshotEventMeasurements::class)->snapshot($event);

        if ($notes !== null) {
            EventAnnotation::query()->create([
                'account_id' => $event->account_id,
                'noise_event_id' => $event->id,
                'author_id' => $fixture->owner->id,
                'kind' => EventAnnotation::KIND_REVIEW,
                'review_status' => ReviewStatus::ConfirmedDisturbance,
                'source_label' => SourceLabel::EngineLike,
                'source_certainty' => SourceCertainty::Suspected,
                'notes' => $notes,
                'created_at' => CarbonImmutable::now(),
            ]);
        }

        return $event->fresh();
    }

    public static function verifiedRecording(NoiseEvent $event, ?string $bytes = null, int $segment = 1, ?CarbonImmutable $capturedAt = null): EventRecording
    {
        $bytes ??= self::wav();
        $uuid = (string) Str::uuid();
        $key = 'recordings/test/'.$event->uuid.'/'.$uuid.'.wav';
        app(EvidenceStorage::class)->disk()->put($key, $bytes);

        return EventRecording::query()->create([
            'uuid' => $uuid,
            'account_id' => $event->account_id,
            'device_id' => $event->device_id,
            'noise_event_id' => $event->id,
            'segment_number' => $segment,
            'capture_started_at' => $capturedAt ?? $event->started_at->subSeconds(10),
            'duration_ms' => 1000,
            'mime_type' => 'audio/wav',
            'codec' => 'pcm_s16le',
            'sample_rate_hz' => 8000,
            'channel_count' => 1,
            'bit_depth' => 16,
            'byte_size' => strlen($bytes),
            'reported_sha256' => hash('sha256', $bytes),
            'declaration_hash' => hash('sha256', $uuid),
            'status' => RecordingStatus::Verified,
            'verified_sha256' => hash('sha256', $bytes),
            'verified_byte_size' => strlen($bytes),
            'verified_duration_ms' => 1000,
            'final_disk' => app(EvidenceStorage::class)->diskName(),
            'final_key' => $key,
            'verified_at' => CarbonImmutable::now(),
            'declared_at' => CarbonImmutable::now(),
        ]);
    }
}
