<?php

use App\Enums\MaintenanceJobKind;
use App\Enums\MembershipRole;
use App\Enums\RecordingStatus;
use App\Models\AuditLog;
use App\Models\EventMeasurementSnapshot;
use App\Models\EventRecording;
use App\Models\Measurement;
use App\Models\NoiseEvent;
use App\Models\User;
use App\Services\Maintenance\MaintenanceQueue;
use App\Services\Retention\ApplyRetention;
use App\Services\Retention\KeepEvent;
use App\Services\Storage\EvidenceStorage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DeviceFixture;
use Tests\Support\EvidenceScenario;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-01T12:00:00Z');
    $this->fixture = DeviceFixture::create();
    $this->storage = app(EvidenceStorage::class);
    $this->start = CarbonImmutable::parse('2026-07-01T11:00:00Z');
    EvidenceScenario::measurements($this->fixture, $this->start, 120);
    $this->event = EvidenceScenario::event($this->fixture, $this->start->addSeconds(30), 10, 'Reviewed');
    $this->recording = EvidenceScenario::verifiedRecording($this->event);
    $this->keptEvent = EvidenceScenario::event($this->fixture, $this->start->addSeconds(80), 5);
    $this->keptRecording = EvidenceScenario::verifiedRecording($this->keptEvent);
    app(KeepEvent::class)->set($this->keptEvent, true, $this->fixture->owner, 'Evidence for HOA');
});

function later(int $days): CarbonImmutable
{
    $now = CarbonImmutable::parse('2026-07-01T12:00:00Z')->addDays($days);
    CarbonImmutable::setTestNow($now);

    return $now;
}

it('freezes affected event snapshots and writes replay receipts before deleting raw rows', function (): void {
    expect(Measurement::count())->toBe(120);
    later(31);

    app(ApplyRetention::class)->run();

    expect(Measurement::count())->toBe(0)
        ->and(DB::table('measurement_receipts')->count())->toBe(120)
        ->and(DB::table('measurement_rollups')->where('resolution_seconds', 60)->count())->toBe(2);

    $snapshot = $this->event->fresh()->latestSnapshot;
    expect($snapshot->status)->toBe(EventMeasurementSnapshot::STATUS_FROZEN)
        ->and($snapshot->captured_intervals)->toBe(50)
        ->and(count($snapshot->decodedRows()))->toBe(50);
});

it('does not delete raw rows whose minute rollups are still dirty', function (): void {
    app(MaintenanceQueue::class)->mark(MaintenanceJobKind::RollupMinute, 'x', $this->fixture->account->id, 'device', $this->fixture->device->id, ['channel' => 'mic-1']);
    DB::table('maintenance_jobs')->update(['bucket_start' => '2026-07-01 11:01:00', 'subject_type' => 'device']);
    later(31);

    $report = app(ApplyRetention::class)->run();

    expect(Measurement::count())->toBe(60)
        ->and($report['accounts'][$this->fixture->account->uuid]['blocked'])->not->toBeEmpty();
});

it('prevents an old retry from resurrecting purged readings', function (): void {
    $records = $this->fixture->records(5, $this->start);
    $now = later(31);
    app(ApplyRetention::class)->run();

    $this->fixture->device->forceFill(['import_window_starts_at' => $this->start->subDay(), 'import_window_expires_at' => $now->addDay()])->save();

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($records))
        ->assertCreated()->assertJson(['inserted_count' => 0, 'duplicate_count' => 5]);

    $changed = $records;
    $changed[0]['laeq_db'] = 90.0;
    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($changed))
        ->assertStatus(409)->assertJsonPath('error.details.conflicts.0.reason', 'changed_after_retention_purge');

    expect(Measurement::count())->toBe(0);
});

it('purges recordings after 90 days but honors keep flags and leaves a tombstone', function (): void {
    later(91);

    app(ApplyRetention::class)->run();

    $purged = $this->recording->fresh();
    $kept = $this->keptRecording->fresh();

    expect($purged->status)->toBe(RecordingStatus::Purged)
        ->and($purged->final_key)->toBeNull()
        ->and($purged->verified_sha256)->not->toBeNull()
        ->and($purged->isPlayable())->toBeFalse()
        ->and($this->storage->disk()->exists($this->recording->final_key))->toBeFalse()
        ->and($kept->status)->toBe(RecordingStatus::Verified)
        ->and($this->storage->disk()->exists($kept->final_key))->toBeTrue()
        ->and($this->event->fresh()->recording_state)->toBe(RecordingStatus::Purged);

    $tombstone = AuditLog::query()->where('action', 'recording.purged')->first();
    expect($tombstone->metadata['verified_sha256'])->toBe($this->recording->verified_sha256);
});

it('rechecks the keep flag under the event lock before purging', function (): void {
    $retention = app(ApplyRetention::class);
    $candidate = EventRecording::query()->find($this->recording->id);

    // Keep is set after the candidate was selected but before the purge locks the event.
    app(KeepEvent::class)->set($this->event, true, $this->fixture->owner);

    expect($retention->purgeRecording($candidate, 'retention'))->toBeFalse()
        ->and($candidate->fresh()->purge_started_at)->toBeNull()
        ->and($this->storage->disk()->exists($this->recording->final_key))->toBeTrue();
});

it('warns that keeping cannot recover audio that was already purged', function (): void {
    app(ApplyRetention::class)->purgeRecording($this->recording, 'test');

    $warnings = app(KeepEvent::class)->set($this->event, true, $this->fixture->owner);

    expect($warnings)->toHaveCount(1)->and($warnings[0])->toContain('cannot recover');
});

it('only lets owners change keep flags', function (): void {
    $reviewer = User::factory()->create();
    $this->fixture->account->users()->attach($reviewer, ['role' => MembershipRole::Reviewer->value]);

    expect(fn () => app(KeepEvent::class)->set($this->event, true, $reviewer))->toThrow(AuthorizationException::class);
});

it('deletes old unkept events with an audit tombstone and preserves kept events', function (): void {
    later(366);

    app(ApplyRetention::class)->run();

    expect(NoiseEvent::query()->find($this->event->id))->toBeNull()
        ->and(NoiseEvent::query()->find($this->keptEvent->id))->not->toBeNull()
        ->and($this->keptEvent->fresh()->snapshots()->count())->toBeGreaterThan(0)
        ->and($this->storage->disk()->exists($this->keptRecording->final_key))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'event.deleted_by_retention')->where('entity_uuid', $this->event->uuid)->exists())->toBeTrue()
        ->and(DB::table('measurement_rollups')->where('resolution_seconds', 3600)->count())->toBe(1)
        ->and(DB::table('measurement_rollups')->where('resolution_seconds', 60)->count())->toBe(0);
});

it('keeps replay receipts longer than the replay window', function (): void {
    later(31);
    app(ApplyRetention::class)->run();
    expect(DB::table('measurement_receipts')->count())->toBe(120);

    later(89);
    app(ApplyRetention::class)->run();
    expect(DB::table('measurement_receipts')->count())->toBe(120);

    later(92);
    app(ApplyRetention::class)->run();
    expect(DB::table('measurement_receipts')->count())->toBe(0)
        ->and(DB::table('measurement_batches')->count())->toBe(0);
});

it('summarizes heartbeats before deleting them and cleans abandoned staging uploads', function (): void {
    $payload = [
        'schema_version' => 1, 'agent_version' => 'agent-0.1.0', 'boot_id' => $this->fixture->bootId, 'uptime_seconds' => 10,
        'capabilities' => ['channels' => ['mic-1'], 'metrics' => ['laeq_db']], 'microphone_state' => 'disconnected',
        'free_disk_bytes' => 1000, 'clock' => ['sync_state' => 'unsynchronized', 'offset_ms' => -2500],
    ];
    $this->devicePost($this->fixture, 'heartbeat', $payload)->assertOk();
    $attempt = $this->recording->uploadAttempts()->create([
        'uuid' => (string) Str::uuid(), 'account_id' => $this->recording->account_id,
        'staging_key' => 'staging/test/abandoned.wav', 'expires_at' => CarbonImmutable::now()->addMinutes(15), 'state' => 'issued',
    ]);
    $this->storage->disk()->put('staging/test/abandoned.wav', 'partial');

    later(8);
    app(ApplyRetention::class)->run();

    $summary = DB::table('device_health_summaries')->first();
    expect(DB::table('device_heartbeats')->count())->toBe(0)
        ->and($summary->heartbeat_count)->toBe(1)
        ->and($summary->microphone_fault_count)->toBe(1)
        ->and($summary->max_abs_clock_offset_ms)->toBe(2500)
        ->and($attempt->fresh()->staging_deleted_at)->not->toBeNull()
        ->and($attempt->fresh()->state->value)->toBe('expired')
        ->and($this->storage->disk()->exists('staging/test/abandoned.wav'))->toBeFalse();
});

it('supports a dry run that changes nothing', function (): void {
    later(400);

    $report = app(ApplyRetention::class)->run(dryRun: true);

    expect($report['accounts'][$this->fixture->account->uuid]['raw_measurements'])->toBe(120)
        ->and(Measurement::count())->toBe(120)
        ->and(NoiseEvent::count())->toBe(2);
});
