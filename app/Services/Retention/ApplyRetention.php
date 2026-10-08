<?php

namespace App\Services\Retention;

use App\Enums\DetectionState;
use App\Enums\ExportStatus;
use App\Enums\MaintenanceJobKind;
use App\Enums\MaintenanceJobStatus;
use App\Enums\RecordingStatus;
use App\Enums\UploadAttemptState;
use App\Models\Account;
use App\Models\Device;
use App\Models\EventMeasurementSnapshot;
use App\Models\EventRecording;
use App\Models\EvidenceExport;
use App\Models\MaintenanceJob;
use App\Models\NoiseEvent;
use App\Models\RecordingUploadAttempt;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Events\RecordingStateProjector;
use App\Services\Events\SnapshotEventMeasurements;
use App\Services\Maintenance\MaintenanceQueue;
use App\Services\Storage\EvidenceStorage;
use App\Support\Rfc3339;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies the owner-configurable retention policy (spec §16) with staged,
 * retryable deletion across the database and object store:
 *
 *  - raw rows are deleted only after their minute rollups are durable (no
 *    pending dirty markers) and overlapping events are snapshotted/frozen;
 *    compact replay receipts are written first so old retries cannot
 *    resurrect purged readings
 *  - kept events preserve their snapshots, recordings, and provenance
 *  - recording purges serialize with keep transitions on the event row lock
 *    and leave an audit tombstone (identity, hash, reason)
 *  - every object deletion failure is recorded for the operations screen
 */
class ApplyRetention
{
    private const CHUNK = 5000;

    public function __construct(
        private readonly EvidenceStorage $storage,
        private readonly SnapshotEventMeasurements $snapshots,
        private readonly RecordingStateProjector $recordingState,
        private readonly MaintenanceQueue $maintenance,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(?Account $only = null, bool $dryRun = false, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $report = ['dry_run' => $dryRun, 'accounts' => []];

        $accounts = Account::query()->when($only, fn ($query) => $query->whereKey($only->id))->orderBy('id')->get();

        foreach ($accounts as $account) {
            $report['accounts'][$account->uuid] = $this->forAccount($account, $dryRun, $now);
        }

        $report['retried_object_deletions'] = $dryRun ? 0 : $this->retryFailedDeletions($only);

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function forAccount(Account $account, bool $dryRun, CarbonImmutable $now): array
    {
        $report = ['blocked' => [], 'failures' => []];

        $report['staging_objects'] = $this->staging($account, $dryRun, $now, $report);
        $report['exports_expired'] = $this->exports($account, $dryRun, $now, $report);
        $report['heartbeats'] = $this->heartbeats($account, $dryRun, $now);
        $report['health_summaries'] = $this->healthSummaries($account, $dryRun, $now);
        $report['recordings_purged'] = $this->recordings($account, $dryRun, $now, $report);
        $report['raw_measurements'] = $this->rawMeasurements($account, $dryRun, $now, $report);
        $report['minute_rollups'] = $this->minuteRollups($account, $dryRun, $now, $report);
        $report['events_deleted'] = $this->events($account, $dryRun, $now, $report);
        [$report['batch_receipts'], $report['measurement_receipts']] = $this->receipts($account, $dryRun, $now);

        return $report;
    }

    /**
     * Receipts must outlive the replay window (backfill window + 1 day).
     */
    public function receiptRetentionDays(Account $account): int
    {
        return max((int) $account->retention('replay_receipts_days'), (int) config('noise.device_api.backfill_days') + 1);
    }

    /**
     * Purge one recording unless its event is kept. Returns true when the
     * recording is purged (now or previously).
     */
    public function purgeRecording(EventRecording $recording, string $reason, bool $ignoreKeep = false): bool
    {
        $proceed = DB::transaction(function () use ($recording, $ignoreKeep): bool {
            $event = NoiseEvent::query()->whereKey($recording->noise_event_id)->lockForUpdate()->first();
            $locked = EventRecording::query()->whereKey($recording->id)->lockForUpdate()->first();

            if ($locked === null || $locked->purged_at !== null) {
                return false;
            }

            if ($locked->purge_started_at === null) {
                // Recheck under the same lock KeepEvent takes.
                if ($event?->keep && ! $ignoreKeep) {
                    return false;
                }

                $locked->forceFill(['purge_started_at' => CarbonImmutable::now()])->save();
            }

            return true;
        });

        $recording->refresh();

        if (! $proceed) {
            return $recording->purged_at !== null;
        }

        $keys = array_values(array_filter([$recording->final_key, $recording->derivative_key]));
        $stagingAttempts = $recording->uploadAttempts()->whereNull('staging_deleted_at')->get();
        $ok = true;

        foreach ($keys as $key) {
            $ok = $this->deleteObject($key, $recording->account_id, 'recording '.$recording->uuid) && $ok;
        }

        foreach ($stagingAttempts as $attempt) {
            if ($this->deleteObject($attempt->staging_key, $recording->account_id, 'staging '.$attempt->uuid)) {
                $attempt->forceFill(['staging_deleted_at' => CarbonImmutable::now()])->save();
            } else {
                $ok = false;
            }
        }

        if (! $ok) {
            return false;
        }

        DB::transaction(function () use ($recording, $reason, $keys): void {
            $locked = EventRecording::query()->whereKey($recording->id)->lockForUpdate()->first();

            if ($locked->purged_at !== null) {
                return;
            }

            $locked->forceFill([
                'status' => RecordingStatus::Purged,
                'purged_at' => CarbonImmutable::now(),
                'purge_reason' => $reason,
                'final_key' => null,
                'derivative_key' => null,
            ])->save();

            // Tombstone: identity and hash survive; playback access does not.
            $this->audit->record('recording.purged', $locked, [
                'recording_uuid' => $locked->uuid,
                'event_id' => $locked->noise_event_id,
                'segment_number' => $locked->segment_number,
                'verified_sha256' => $locked->verified_sha256,
                'reported_sha256' => $locked->reported_sha256,
                'byte_size' => $locked->verified_byte_size ?? $locked->byte_size,
                'previous_object_keys' => $keys,
                'reason' => $reason,
            ], accountId: $locked->account_id);

            if ($locked->noiseEvent !== null) {
                $this->recordingState->refresh($locked->noiseEvent);
            }
        });

        return true;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function recordings(Account $account, bool $dryRun, CarbonImmutable $now, array &$report): int
    {
        $cutoff = $now->subDays((int) $account->retention('recordings_days'));
        $query = EventRecording::query()
            ->where('account_id', $account->id)
            ->whereNull('purged_at')
            ->where(function ($query) use ($cutoff): void {
                $query->whereNotNull('purge_started_at')
                    ->orWhere(fn ($expired) => $expired
                        ->where('capture_started_at', '<', Rfc3339::toDatabase($cutoff))
                        ->whereHas('noiseEvent', fn ($event) => $event->where('keep', false)));
            });

        if ($dryRun) {
            return $query->count();
        }

        $purged = 0;

        foreach ($query->orderBy('id')->limit(1000)->get() as $recording) {
            if ($this->purgeRecording($recording, 'Retention: recordings older than '.$account->retention('recordings_days').' days')) {
                $purged++;
            } elseif ($recording->fresh()->purge_started_at !== null) {
                $report['failures'][] = "Recording {$recording->uuid}: object deletion failed; will retry.";
            }
        }

        return $purged;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function rawMeasurements(Account $account, bool $dryRun, CarbonImmutable $now, array &$report): int
    {
        $cutoff = $now->subDays((int) $account->retention('raw_measurements_days'));
        $pre = (int) config('noise.events.snapshot_pre_seconds');
        $total = 0;

        foreach (Device::query()->where('account_id', $account->id)->orderBy('id')->get() as $device) {
            $safe = $cutoff;

            // Dependent minute rollups must be durable before raw rows go.
            $pendingMinute = MaintenanceJob::query()
                ->where('kind', MaintenanceJobKind::RollupMinute->value)
                ->where('subject_type', 'device')
                ->where('subject_id', $device->id)
                ->min('bucket_start');

            if ($pendingMinute !== null && CarbonImmutable::parse($pendingMinute, 'UTC')->lessThan($safe)) {
                $safe = CarbonImmutable::parse($pendingMinute, 'UTC');
                $report['blocked'][] = "Device {$device->name}: raw readings from ".Rfc3339::format($safe).' kept until pending minute rollups are rebuilt.';
            }

            // Affected events must be snapshotted (and frozen or marked partial).
            $events = NoiseEvent::query()
                ->where('device_id', $device->id)
                ->where('started_at', '<', Rfc3339::toDatabase($safe->addSeconds($pre)))
                ->whereDoesntHave('snapshots', fn ($query) => $query->where('status', EventMeasurementSnapshot::STATUS_FROZEN))
                ->orderBy('started_at')
                ->get();

            foreach ($events as $event) {
                if ($dryRun) {
                    continue;
                }

                $snapshot = $this->snapshots->snapshot($event, $now, forceFreeze: true);

                if (! $snapshot->isFrozen()) {
                    [$windowStart] = $this->snapshots->window($event);

                    if ($event->detection_state === DetectionState::Open && $windowStart->lessThan($safe)) {
                        $safe = $windowStart;
                        $report['blocked'][] = "Device {$device->name}: event {$event->uuid} is still open; raw readings from ".Rfc3339::format($safe).' are kept for its snapshot.';
                    }
                }
            }

            $base = DB::table('measurements')
                ->where('account_id', $account->id)
                ->where('device_id', $device->id)
                ->where('captured_at', '<', Rfc3339::toDatabase($safe));

            if ($dryRun) {
                $total += (clone $base)->count();

                continue;
            }

            do {
                $rows = (clone $base)->orderBy('id')->limit(self::CHUNK)->get(['id', 'channel', 'boot_id', 'sequence', 'row_hash', 'captured_at']);

                if ($rows->isEmpty()) {
                    break;
                }

                DB::transaction(function () use ($rows, $device, $now): void {
                    $deletedAt = Rfc3339::toDatabase($now);

                    foreach ($rows->chunk(1000) as $chunk) {
                        $bindings = [];

                        foreach ($chunk as $row) {
                            array_push($bindings, $device->id, $row->channel, $row->boot_id, $row->sequence, $row->row_hash, $row->captured_at, $deletedAt);
                        }

                        DB::statement(
                            'INSERT INTO measurement_receipts (device_id, channel, boot_id, sequence, row_hash, captured_at, raw_deleted_at) VALUES '
                            .implode(',', array_fill(0, $chunk->count(), '(?,?,?,?,?,?,?)'))
                            .' ON DUPLICATE KEY UPDATE raw_deleted_at = raw_deleted_at',
                            $bindings,
                        );
                    }

                    DB::table('measurements')->whereIn('id', $rows->pluck('id'))->delete();
                });

                $total += $rows->count();
            } while ($rows->count() === self::CHUNK);
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function minuteRollups(Account $account, bool $dryRun, CarbonImmutable $now, array &$report): int
    {
        $days = $account->retention('minute_rollups_days');

        if ($days === null) {
            return 0;
        }

        $total = 0;

        foreach (Device::query()->where('account_id', $account->id)->pluck('id') as $deviceId) {
            $safe = $now->subDays($days)->startOfHour();
            $pendingHour = MaintenanceJob::query()
                ->whereIn('kind', [MaintenanceJobKind::RollupHour->value, MaintenanceJobKind::RollupMinute->value])
                ->where('subject_type', 'device')
                ->where('subject_id', $deviceId)
                ->min('bucket_start');

            if ($pendingHour !== null && CarbonImmutable::parse($pendingHour, 'UTC')->startOfHour()->lessThan($safe)) {
                $safe = CarbonImmutable::parse($pendingHour, 'UTC')->startOfHour();
                $report['blocked'][] = "Device {$deviceId}: minute rollups from ".Rfc3339::format($safe).' kept until hour rollups are rebuilt.';
            }

            $query = DB::table('measurement_rollups')
                ->where('account_id', $account->id)
                ->where('device_id', $deviceId)
                ->where('resolution_seconds', 60)
                ->where('bucket_start', '<', $safe->format('Y-m-d H:i:s'));

            if ($dryRun) {
                $total += $query->count();

                continue;
            }

            do {
                $deleted = (clone $query)->orderBy('id')->limit(self::CHUNK)->delete();
                $total += $deleted;
            } while ($deleted === self::CHUNK);
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function events(Account $account, bool $dryRun, CarbonImmutable $now, array &$report): int
    {
        $cutoff = $now->subDays((int) $account->retention('events_days'));
        $query = NoiseEvent::query()
            ->where('account_id', $account->id)
            ->where('keep', false)
            ->where('started_at', '<', Rfc3339::toDatabase($cutoff));

        if ($dryRun) {
            return $query->count();
        }

        $deleted = 0;

        foreach ($query->orderBy('id')->limit(500)->get() as $event) {
            foreach ($event->recordings()->whereNull('purged_at')->get() as $recording) {
                $this->purgeRecording($recording, 'Retention: event metadata older than '.$account->retention('events_days').' days');
            }

            if ($this->deleteEvent($event)) {
                $deleted++;
            } else {
                $report['failures'][] = "Event {$event->uuid}: not deleted (kept, or recording objects still pending deletion).";
            }
        }

        return $deleted;
    }

    public function deleteEvent(NoiseEvent $event, string $auditAction = 'event.deleted_by_retention', ?User $user = null): bool
    {
        return DB::transaction(function () use ($event, $auditAction, $user): bool {
            $event = NoiseEvent::query()->whereKey($event->id)->lockForUpdate()->first();

            if ($event === null || $event->keep || $event->recordings()->whereNull('purged_at')->exists()) {
                return false;
            }

            $recordings = $event->recordings()->get(['id', 'uuid', 'segment_number', 'verified_sha256', 'reported_sha256']);

            $this->audit->record($auditAction, $event, [
                'event_uuid' => $event->uuid,
                'device_id' => $event->device_id,
                'started_at' => Rfc3339::format($event->started_at),
                'current_revision' => $event->current_revision,
                'review_status' => $event->review_status->value,
                'recordings' => $recordings->map(fn ($recording): array => [
                    'uuid' => $recording->uuid,
                    'segment_number' => $recording->segment_number,
                    'verified_sha256' => $recording->verified_sha256,
                    'reported_sha256' => $recording->reported_sha256,
                ])->all(),
            ], accountId: $event->account_id, user: $user);

            $event->forceFill(['latest_review_annotation_id' => null])->save();
            DB::table('event_group_noise_event')->where('noise_event_id', $event->id)->delete();
            DB::table('event_annotations')->where('noise_event_id', $event->id)->update(['supersedes_id' => null]);
            DB::table('event_annotations')->where('noise_event_id', $event->id)->delete();
            DB::table('event_measurement_snapshots')->where('noise_event_id', $event->id)->delete();
            DB::table('noise_event_revisions')->where('noise_event_id', $event->id)->delete();
            DB::table('recording_upload_attempts')->whereIn('event_recording_id', $recordings->pluck('id'))->delete();
            DB::table('event_recordings')->where('noise_event_id', $event->id)->delete();
            DB::table('noise_events')->where('id', $event->id)->delete();

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function staging(Account $account, bool $dryRun, CarbonImmutable $now, array &$report): int
    {
        $query = RecordingUploadAttempt::query()
            ->where('account_id', $account->id)
            ->whereNull('staging_deleted_at')
            ->where('created_at', '<', $now->subHours((int) $account->retention('staging_hours')))
            ->whereNotIn('state', [UploadAttemptState::Completed->value, UploadAttemptState::Verifying->value]);

        if ($dryRun) {
            return $query->count();
        }

        $cleaned = 0;

        foreach ($query->orderBy('id')->limit(2000)->get() as $attempt) {
            if (! $this->deleteObject($attempt->staging_key, $account->id, 'staging '.$attempt->uuid)) {
                $report['failures'][] = "Staging object for attempt {$attempt->uuid} could not be deleted; will retry.";

                continue;
            }

            $attempt->forceFill([
                'staging_deleted_at' => $now,
                'state' => $attempt->state === UploadAttemptState::Issued ? UploadAttemptState::Expired : $attempt->state,
            ])->save();
            $cleaned++;
        }

        return $cleaned;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function exports(Account $account, bool $dryRun, CarbonImmutable $now, array &$report): int
    {
        $query = EvidenceExport::query()
            ->where('account_id', $account->id)
            ->where('status', ExportStatus::Ready->value)
            ->where('expires_at', '<', Rfc3339::toDatabase($now));

        if ($dryRun) {
            return $query->count();
        }

        $expired = 0;

        foreach ($query->get() as $export) {
            if ($export->object_key !== null && ! $this->deleteObject($export->object_key, $account->id, 'export '.$export->uuid)) {
                $report['failures'][] = "Export {$export->uuid}: object deletion failed; will retry.";

                continue;
            }

            $export->forceFill(['status' => ExportStatus::Expired, 'object_deleted_at' => $now])->save();
            $this->audit->record('export.expired', $export, ['sha256' => $export->export_sha256], accountId: $account->id);
            $expired++;
        }

        return $expired;
    }

    private function heartbeats(Account $account, bool $dryRun, CarbonImmutable $now): int
    {
        $cutoff = $now->subDays((int) $account->retention('heartbeats_days'))->startOfDay();
        $base = DB::table('device_heartbeats')->where('account_id', $account->id)->where('received_at', '<', Rfc3339::toDatabase($cutoff));

        if ($dryRun) {
            return (clone $base)->count();
        }

        $groups = (clone $base)->selectRaw('device_id, DATE(received_at) as day')->groupBy('device_id', 'day')->get();
        $deleted = 0;

        foreach ($groups as $group) {
            $deleted += DB::transaction(function () use ($account, $group): int {
                $dayStart = CarbonImmutable::parse($group->day, 'UTC')->startOfDay();
                $rows = DB::table('device_heartbeats')
                    ->where('device_id', $group->device_id)
                    ->where('received_at', '>=', Rfc3339::toDatabase($dayStart))
                    ->where('received_at', '<', Rfc3339::toDatabase($dayStart->addDay()))
                    ->lockForUpdate()
                    ->get();

                if ($rows->isEmpty()) {
                    return 0;
                }

                $existing = DB::table('device_health_summaries')->where('device_id', $group->device_id)->where('day', $dayStart->toDateString())->first();
                $minOrNull = fn ($values) => $values->filter(fn ($value) => $value !== null)->min();
                $maxOrNull = fn ($values) => $values->filter(fn ($value) => $value !== null)->max();
                $offsets = $rows->pluck('clock_offset_ms')->filter(fn ($value) => $value !== null)->map(fn ($value) => abs((int) $value));
                $versions = array_values(array_unique(array_merge(json_decode($existing->agent_versions ?? '[]', true), $rows->pluck('agent_version')->filter()->unique()->values()->all())));

                $values = [
                    'account_id' => $account->id,
                    'heartbeat_count' => ($existing->heartbeat_count ?? 0) + $rows->count(),
                    'min_free_disk_bytes' => $minOrNull(collect([$existing?->min_free_disk_bytes, $minOrNull($rows->pluck('free_disk_bytes'))])),
                    'max_queued_measurement_count' => $maxOrNull(collect([$existing?->max_queued_measurement_count, $maxOrNull($rows->pluck('queued_measurement_count'))])),
                    'max_pending_audio_bytes' => $maxOrNull(collect([$existing?->max_pending_audio_bytes, $maxOrNull($rows->pluck('pending_audio_bytes'))])),
                    'unsynchronized_clock_count' => ($existing->unsynchronized_clock_count ?? 0) + $rows->where('clock_sync_state', '!=', 'synchronized')->count(),
                    'microphone_fault_count' => ($existing->microphone_fault_count ?? 0) + $rows->whereIn('microphone_state', ['disconnected', 'error'])->count(),
                    'max_abs_clock_offset_ms' => $maxOrNull(collect([$existing?->max_abs_clock_offset_ms, $offsets->max()])),
                    'dropped_intervals' => ($existing->dropped_intervals ?? 0) + (int) $rows->sum('recent_dropped_intervals'),
                    'agent_versions' => json_encode($versions),
                    'updated_at' => CarbonImmutable::now(),
                ];

                DB::table('device_health_summaries')->updateOrInsert(
                    ['device_id' => $group->device_id, 'day' => $dayStart->toDateString()],
                    $existing === null ? [...$values, 'created_at' => CarbonImmutable::now()] : $values,
                );

                return DB::table('device_heartbeats')->whereIn('id', $rows->pluck('id'))->delete();
            });
        }

        return $deleted;
    }

    private function healthSummaries(Account $account, bool $dryRun, CarbonImmutable $now): int
    {
        $query = DB::table('device_health_summaries')
            ->where('account_id', $account->id)
            ->where('day', '<', $now->subDays((int) $account->retention('health_summaries_days'))->toDateString());

        return $dryRun ? $query->count() : $query->delete();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function receipts(Account $account, bool $dryRun, CarbonImmutable $now): array
    {
        $cutoff = Rfc3339::toDatabase($now->subDays($this->receiptRetentionDays($account)));
        $deviceIds = Device::query()->where('account_id', $account->id)->pluck('id')->all();

        $batches = DB::table('measurement_batches')
            ->where('account_id', $account->id)
            ->where('received_at', '<', $cutoff)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('measurements')->whereColumn('measurements.batch_id', 'measurement_batches.id'));

        $receipts = DB::table('measurement_receipts')->whereIn('device_id', $deviceIds ?: [0])->where('captured_at', '<', $cutoff);

        if ($dryRun) {
            return [$batches->count(), $receipts->count()];
        }

        return [$batches->delete(), $receipts->delete()];
    }

    /**
     * Delete one object; on failure record a visible, retryable cleanup item.
     */
    public function deleteObject(string $key, ?int $accountId, string $description): bool
    {
        try {
            $this->storage->disk()->delete($key);

            return true;
        } catch (Throwable $exception) {
            $this->maintenance->mark(MaintenanceJobKind::DeleteObject, $key, $accountId, 'object', null, ['key' => $key, 'description' => $description]);
            MaintenanceJob::query()
                ->where('dedupe_key', hash('sha256', MaintenanceJobKind::DeleteObject->value.'|'.$key))
                ->update([
                    'status' => MaintenanceJobStatus::Failed->value,
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error' => mb_substr($exception::class.': '.$exception->getMessage(), 0, 2000),
                ]);

            return false;
        }
    }

    private function retryFailedDeletions(?Account $only): int
    {
        $jobs = MaintenanceJob::query()
            ->where('kind', MaintenanceJobKind::DeleteObject->value)
            ->when($only, fn ($query) => $query->where('account_id', $only->id))
            ->limit(500)
            ->get();

        $retried = 0;

        foreach ($jobs as $job) {
            try {
                $this->storage->disk()->delete($job->payload['key']);
                $job->delete();
                $retried++;
            } catch (Throwable $exception) {
                $job->forceFill([
                    'attempts' => $job->attempts + 1,
                    'last_error' => mb_substr($exception::class.': '.$exception->getMessage(), 0, 2000),
                ])->save();
            }
        }

        return $retried;
    }
}
