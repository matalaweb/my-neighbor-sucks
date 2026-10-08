<?php

namespace App\Console\Commands;

use App\Enums\DetectionState;
use App\Enums\ExportStatus;
use App\Enums\MaintenanceJobKind;
use App\Enums\RecordingStatus;
use App\Jobs\BuildExportJob;
use App\Jobs\ProcessEventSnapshots;
use App\Jobs\ProcessRollups;
use App\Jobs\VerifyRecordings;
use App\Models\EventMeasurementSnapshot;
use App\Models\EvidenceExport;
use App\Models\NoiseEvent;
use App\Services\Events\RecordingStateProjector;
use App\Services\Maintenance\MaintenanceQueue;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Periodic reconciler (spec §17). Queue dispatches are only hints; this
 * re-dispatches work for any durable record left behind, settles event
 * snapshots and recording states, and (with --deep) re-marks minute buckets
 * whose rollups disagree with raw rows.
 */
#[Signature('noise:reconcile {--deep : Compare raw row counts with minute rollups for the last 26 hours}')]
#[Description('Re-dispatch durable outstanding work and settle derived state')]
class Reconcile extends Command
{
    public function handle(MaintenanceQueue $maintenance, RecordingStateProjector $recordingState): int
    {
        $now = CarbonImmutable::now();

        if ($maintenance->hasDue([MaintenanceJobKind::RollupMinute, MaintenanceJobKind::RollupHour])) {
            ProcessRollups::dispatch();
        }

        if ($maintenance->hasDue([MaintenanceJobKind::EventSnapshot])) {
            ProcessEventSnapshots::dispatch();
        }

        if ($maintenance->hasDue([MaintenanceJobKind::VerifyRecording])) {
            VerifyRecordings::dispatch();
        }

        // Exports stuck before completion are rebuilt from their frozen selection.
        EvidenceExport::query()
            ->whereIn('status', [ExportStatus::Pending, ExportStatus::Building])
            ->where('updated_at', '<', $now->subMinutes(15))
            ->each(fn (EvidenceExport $export) => BuildExportJob::dispatch($export->id));

        // Finalized events whose snapshot is still collecting past the settle period.
        $settleBefore = $now->subHours((int) config('noise.events.snapshot_settle_hours'));
        $settle = NoiseEvent::query()
            ->where('detection_state', DetectionState::Finalized)
            ->where('ended_at', '<', $settleBefore)
            ->whereHas('snapshots', fn ($query) => $query->where('status', EventMeasurementSnapshot::STATUS_COLLECTING))
            ->limit(500)
            ->get(['id', 'account_id']);

        foreach ($settle as $event) {
            $maintenance->mark(MaintenanceJobKind::EventSnapshot, (string) $event->id, $event->account_id, 'noise_event', $event->id);
        }

        if ($settle->isNotEmpty()) {
            ProcessEventSnapshots::dispatch();
        }

        // Expected recordings never declared become "missing" (a later declaration recovers).
        NoiseEvent::query()
            ->where('recording_expected', true)
            ->where('recording_state', RecordingStatus::Pending)
            ->where('detection_state', DetectionState::Finalized)
            ->where('ended_at', '<', $now->subHours((int) config('noise.events.recording_missing_after_hours')))
            ->whereDoesntHave('recordings')
            ->limit(500)
            ->get()
            ->each(fn (NoiseEvent $event) => $recordingState->refresh($event));

        if ($this->option('deep')) {
            $this->deep($maintenance, $now);
        }

        return self::SUCCESS;
    }

    private function deep(MaintenanceQueue $maintenance, CarbonImmutable $now): void
    {
        $from = $now->subHours(26)->startOfMinute();
        $raw = DB::table('measurements')
            ->where('captured_at', '>=', $from)
            ->selectRaw("account_id, device_id, channel, DATE_FORMAT(captured_at, '%Y-%m-%d %H:%i:00') AS minute, COUNT(*) AS total")
            ->groupBy('account_id', 'device_id', 'channel', 'minute')
            ->get();

        $rolled = DB::table('measurement_rollups as r')
            ->join('measurement_streams as s', 's.id', '=', 'r.stream_id')
            ->where('r.resolution_seconds', 60)
            ->where('r.bucket_start', '>=', $from)
            ->selectRaw("r.device_id, s.channel, DATE_FORMAT(r.bucket_start, '%Y-%m-%d %H:%i:00') AS minute, SUM(r.row_count) AS total")
            ->groupBy('r.device_id', 's.channel', 'minute')
            ->get()
            ->keyBy(fn ($row) => $row->device_id.'|'.$row->channel.'|'.$row->minute);

        $dirty = [];

        foreach ($raw as $row) {
            $key = $row->device_id.'|'.$row->channel.'|'.$row->minute;

            if ((int) ($rolled[$key]->total ?? 0) !== (int) $row->total) {
                $dirty[] = [
                    'kind' => MaintenanceJobKind::RollupMinute,
                    'key' => $key,
                    'account_id' => (int) $row->account_id,
                    'subject_type' => 'device',
                    'subject_id' => (int) $row->device_id,
                    'bucket_start' => $row->minute,
                    'payload' => ['channel' => $row->channel],
                ];
            }
        }

        $maintenance->markMany($dirty);

        if ($dirty !== []) {
            $this->warn(count($dirty).' minute bucket(s) re-marked for rebuild.');
            ProcessRollups::dispatch();
        }
    }
}
