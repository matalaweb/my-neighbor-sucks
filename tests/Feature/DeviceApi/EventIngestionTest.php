<?php

use App\Enums\CalibrationState;
use App\Enums\CompletenessState;
use App\Models\EventAnnotation;
use App\Models\EventMeasurementSnapshot;
use App\Models\NoiseEvent;
use App\Models\NoiseEventRevision;
use App\Services\Events\SnapshotEventMeasurements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\DeviceFixture;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-08T12:20:00Z');
    $this->fixture = DeviceFixture::create();
    $this->start = CarbonImmutable::parse('2026-10-08T12:15:00Z');
    $this->eventId = (string) Str::uuid();
});

function postEvent($test, DeviceFixture $fixture, array $payload)
{
    return $test->devicePost($fixture, 'events', $payload);
}

it('creates an open event with a null end time', function (): void {
    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 1, $this->start, null))
        ->assertCreated()
        ->assertJson(['event_id' => $this->eventId, 'outcome' => 'stored', 'detection_state' => 'open', 'recording_state' => 'pending']);

    $event = NoiseEvent::query()->sole();
    expect($event->ended_at)->toBeNull()
        ->and($event->property_id)->toBe($this->fixture->property->id)
        ->and($event->account_id)->toBe($this->fixture->account->id);
});

it('never rolls back the projection and keeps every revision payload', function (): void {
    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 1, $this->start, null))->assertCreated();
    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 3, $this->start, null, ['detection' => ['trigger_value_db' => 88.0]]))->assertCreated();

    // Identical repeat of revision 3.
    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 3, $this->start, null, ['detection' => ['trigger_value_db' => 88.0]]))
        ->assertOk()->assertJson(['outcome' => 'duplicate', 'current_revision' => 3]);

    // Older, previously unseen revision: stored for history, projection unchanged.
    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 2, $this->start, null, ['detection' => ['trigger_value_db' => 70.0]]))
        ->assertOk()->assertJson(['outcome' => 'stored_superseded', 'applied_to_projection' => false, 'current_revision' => 3]);

    // Changed content under an existing revision.
    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 3, $this->start, null, ['detection' => ['trigger_value_db' => 91.0]]))
        ->assertStatus(409)->assertJsonPath('error.code', 'event_revision_conflict');

    $event = NoiseEvent::query()->sole();
    expect($event->current_revision)->toBe(3)
        ->and($event->trigger_value_db)->toBe(88.0)
        ->and(NoiseEventRevision::query()->orderBy('revision')->pluck('applied_to_projection', 'revision')->all())->toBe([1 => true, 2 => false, 3 => true]);
});

it('treats finalized events as terminal', function (): void {
    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 1, $this->start, null))->assertCreated();
    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 2, $this->start, $this->start->addSeconds(8)))
        ->assertCreated()->assertJson(['detection_state' => 'finalized']);

    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 3, $this->start, $this->start->addSeconds(20)))
        ->assertStatus(409)->assertJsonPath('error.code', 'event_terminal');

    expect(NoiseEvent::query()->sole()->ended_at->toIso8601ZuluString())->toBe('2026-10-08T12:15:08Z');
});

it('lets a later final revision close an event marked incomplete by a reviewer', function (): void {
    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 1, $this->start, null))->assertCreated();
    NoiseEvent::query()->sole()->forceFill(['marked_incomplete_at' => CarbonImmutable::now()])->save();

    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 2, $this->start, $this->start->addSeconds(5)))->assertCreated();

    expect(NoiseEvent::query()->sole()->detection_state->value)->toBe('finalized');
});

it('marks a finalized event whose observation stopped as interrupted, not as a normal end', function (): void {
    $end = $this->start->addSeconds(12);
    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 1, $this->start, $end, ['quality_flags' => ['audio_dropout', 'incomplete_interval']]))
        ->assertCreated();
    postEvent($this, $this->fixture, $this->fixture->event((string) Str::uuid(), 1, $this->start->addMinute(), $end->addMinute()))->assertCreated();

    [$interrupted, $normal] = NoiseEvent::query()->orderBy('started_at')->get()->all();
    expect($interrupted->observationInterrupted())->toBeTrue()
        ->and($interrupted->durationMs())->toBe(12000)
        ->and($normal->observationInterrupted())->toBeFalse();
});

it('marks an event ended at the maximum duration as a lower-bound duration', function (): void {
    $start = $this->start->subMinutes(20);
    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 1, $start, $start->addMinutes(10), ['quality_flags' => ['max_duration_reached']]))
        ->assertCreated();

    $event = NoiseEvent::query()->sole();
    expect($event->endedAtMaxDuration())->toBeTrue()
        ->and($event->observationInterrupted())->toBeFalse()
        ->and($event->durationIsLowerBound())->toBeTrue()
        ->and($event->durationMs())->toBe(600000);
});

it('annotations never mutate original revision payloads', function (): void {
    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 1, $this->start, $this->start->addSeconds(5)))->assertCreated();
    $revision = NoiseEventRevision::query()->sole();
    $before = [$revision->payload, $revision->payload_hash];
    $event = NoiseEvent::query()->sole();

    EventAnnotation::query()->create([
        'account_id' => $event->account_id,
        'noise_event_id' => $event->id,
        'author_id' => $this->fixture->owner->id,
        'kind' => EventAnnotation::KIND_REVIEW,
        'review_status' => 'confirmed_disturbance',
        'source_label' => 'engine_like',
        'notes' => 'Loud exhaust heard.',
        'created_at' => CarbonImmutable::now(),
    ]);

    $after = NoiseEventRevision::query()->sole();
    expect([$after->payload, $after->payload_hash])->toBe($before);

    $annotation = EventAnnotation::query()->sole();
    expect(fn () => $annotation->update(['notes' => 'changed']))->toThrow(LogicException::class);
});

it('rejects absolute summary values for an uncalibrated profile', function (): void {
    $fixture = DeviceFixture::create(CalibrationState::Uncalibrated);
    $payload = $fixture->event($this->eventId, 1, $this->start, null, ['summary' => ['laeq_db' => 70.0]]);

    postEvent($this, $fixture, $payload)->assertStatus(422)
        ->assertJsonPath('error.details.errors', fn (array $errors): bool => array_key_exists('summary.laeq_db', $errors));

    postEvent($this, $fixture, $fixture->event($this->eventId, 1, $this->start, null))->assertCreated();
});

it('rejects unknown provenance and implausible future timestamps', function (): void {
    $other = DeviceFixture::create();

    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 1, $this->start, null, ['profile_id' => $other->profile->uuid]))
        ->assertStatus(422)->assertJsonPath('error.code', 'unknown_provenance');

    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 1, CarbonImmutable::now()->addMinutes(10), null))
        ->assertStatus(422)->assertJsonPath('error.code', 'clock_future_timestamp');

    postEvent($this, $this->fixture, [...$this->fixture->event($this->eventId, 1, $this->start, null), 'extra' => true])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    expect(NoiseEvent::count())->toBe(0);
});

it('does not let another device read or write an event identity', function (): void {
    $other = DeviceFixture::create();
    postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 1, $this->start, null))->assertCreated();

    // Same event UUID from a different device is a separate event in that device's scope.
    postEvent($this, $other, $other->event($this->eventId, 1, $this->start, null))->assertCreated();
    expect(NoiseEvent::query()->where('uuid', $this->eventId)->count())->toBe(2);

    $onlyMine = (string) Str::uuid();
    postEvent($this, $this->fixture, $this->fixture->event($onlyMine, 1, $this->start, null))->assertCreated();
    $this->devicePost($other, 'events/'.$onlyMine.'/recordings', ['schema_version' => 1])->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

describe('event measurement snapshots', function (): void {
    beforeEach(function (): void {
        $this->snapshots = app(SnapshotEventMeasurements::class);
    });

    function ingest($test, DeviceFixture $fixture, int $count, CarbonImmutable $start, int $firstSequence): void
    {
        $test->devicePost($fixture, 'measurements/batches', $fixture->batch($fixture->records($count, $start, $firstSequence)))->assertCreated();
    }

    it('supplements a collecting snapshot with late readings and freezes when complete', function (): void {
        // Window: 12:14:50 → 12:15:40 (10 s before start, 30 s after end at 12:15:10) = 50 s.
        ingest($this, $this->fixture, 20, $this->start->subSeconds(10), 1);
        postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 1, $this->start, $this->start->addSeconds(10)))->assertCreated();
        $this->snapshots->processDirty();

        $snapshot = EventMeasurementSnapshot::query()->sole();
        expect($snapshot->status)->toBe('collecting')
            ->and($snapshot->expected_intervals)->toBe(50)
            ->and($snapshot->captured_intervals)->toBe(20)
            ->and(NoiseEvent::query()->sole()->completeness_state)->toBe(CompletenessState::Pending);

        ingest($this, $this->fixture, 30, $this->start->addSeconds(10), 21);
        $this->snapshots->processDirty();

        $snapshot->refresh();
        $event = NoiseEvent::query()->sole();
        expect($snapshot->status)->toBe('frozen')
            ->and($snapshot->completeness)->toBe(CompletenessState::Complete)
            ->and($snapshot->captured_intervals)->toBe(50)
            ->and(count($snapshot->decodedRows()))->toBe(50)
            ->and($snapshot->decodedRows()[0])->toHaveKeys(['boot_id', 'sequence', 'profile_id', 'deployment_id', 'calibration_id', 'row_sha256', 'batch_id'])
            ->and($event->completeness_state)->toBe(CompletenessState::Complete)
            ->and($event->server_summary['metrics']['laeq_db'])->toEqualWithDelta(45.0, 0.0001)
            ->and($event->server_summary['detection_measured_ms'])->toBe(10000)
            ->and($event->agent_summary['laeq_db'])->toBe(71.2);
    });

    it('freezes as partial after the settle period and versions later readings', function (): void {
        ingest($this, $this->fixture, 20, $this->start->subSeconds(10), 1);
        postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 1, $this->start, $this->start->addSeconds(10)))->assertCreated();

        CarbonImmutable::setTestNow('2026-10-09T13:00:00Z');
        $this->snapshots->processDirty();

        $first = EventMeasurementSnapshot::query()->sole();
        expect($first->status)->toBe('frozen')
            ->and($first->completeness)->toBe(CompletenessState::Partial)
            ->and($first->limitations)->toContain('20 of 50')
            ->and($first->missing_ranges)->toEqual([['start' => '2026-10-08T12:15:10.000Z', 'end' => '2026-10-08T12:15:40.000Z']]);
        $firstHash = $first->content_hash;

        ingest($this, $this->fixture, 30, $this->start->addSeconds(10), 21);
        $this->snapshots->processDirty();

        $versions = EventMeasurementSnapshot::query()->orderBy('version')->get();
        expect($versions)->toHaveCount(2)
            ->and($versions[0]->content_hash)->toBe($firstHash)
            ->and($versions[0]->captured_intervals)->toBe(20)
            ->and($versions[1]->version)->toBe(2)
            ->and($versions[1]->completeness)->toBe(CompletenessState::Complete)
            ->and(fn () => $versions[0]->forceFill(['captured_intervals' => 1])->save())->toThrow(LogicException::class);
    });

    it('bounds an open event by the latest available data without padding', function (): void {
        ingest($this, $this->fixture, 15, $this->start->subSeconds(10), 1);
        postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 1, $this->start, null))->assertCreated();
        $this->snapshots->processDirty();

        $snapshot = EventMeasurementSnapshot::query()->sole();
        expect($snapshot->window_end)->toBeNull()
            ->and($snapshot->expected_intervals)->toBe(15)
            ->and($snapshot->captured_intervals)->toBe(15)
            ->and($snapshot->status)->toBe('collecting');
    });

    it('marks an event with no available readings unavailable once settled', function (): void {
        postEvent($this, $this->fixture, $this->fixture->event($this->eventId, 1, $this->start, $this->start->addSeconds(5)))->assertCreated();
        CarbonImmutable::setTestNow('2026-10-10T00:00:00Z');
        $this->snapshots->processDirty();

        expect(NoiseEvent::query()->sole()->completeness_state)->toBe(CompletenessState::Unavailable);
    });
});
