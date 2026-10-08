<?php

namespace App\Services\Measurements;

use App\Enums\ClockSyncState;
use App\Enums\MicrophoneState;
use App\Enums\QualityFlag;
use App\Enums\RecordingStatus;
use App\Enums\ReviewStatus;
use App\Models\Device;
use App\Models\MeasurementStream;
use App\Models\NoiseEvent;
use App\Models\Property;
use App\Support\Decibels;
use App\Support\LocalTime;
use App\Support\Rfc3339;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Near-real-time property overview (spec §12). Values are always labelled
 * with their metric; freshness, coverage, and calibration state accompany
 * every number.
 */
class DashboardSummary
{
    /**
     * @return array<string, mixed>
     */
    public function device(Device $device, string $localDate, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $timezone = $device->property->timezone;
        [$dayStart, $dayEnd] = LocalTime::dayRange($localDate, $timezone);
        $elapsedEnd = $now->lessThan($dayEnd) ? $now : $dayEnd;
        $heartbeat = $device->latestHeartbeat;

        // One index-ordered lookup per channel (account/device/channel/captured_at)
        // instead of a cross-channel sort over the whole history.
        $latest = DB::table('measurement_streams')->where('device_id', $device->id)->distinct()->pluck('channel')
            ->map(fn (string $channel) => DB::table('measurements')
                ->where('account_id', $device->account_id)
                ->where('device_id', $device->id)
                ->where('channel', $channel)
                ->orderByDesc('captured_at')
                ->first(['stream_id', 'channel', 'captured_at', 'received_at', 'laeq_db', 'rms_dbfs', 'quality_flags']))
            ->filter()
            ->sortByDesc('captured_at')
            ->first();

        $latestStream = $latest ? MeasurementStream::query()->find($latest->stream_id) : null;
        $latestCaptured = $latest ? CarbonImmutable::parse($latest->captured_at, 'UTC') : null;
        $latestMask = $latest ? (int) $latest->quality_flags : 0;

        $streams = MeasurementStream::query()->with(['profile'])->where('device_id', $device->id)->get()->keyBy('id');

        $todayMaxima = DB::table('measurement_rollups')
            ->where('account_id', $device->account_id)
            ->where('device_id', $device->id)
            ->where('resolution_seconds', 60)
            ->where('bucket_start', '>=', $dayStart->format('Y-m-d H:i:s'))
            ->where('bucket_start', '<', $dayEnd->format('Y-m-d H:i:s'))
            ->groupBy('stream_id')
            ->selectRaw('stream_id, MAX(lafmax_max) AS lafmax_max, SUM(lafmax_valid_ms) AS lafmax_valid_ms, SUM(laeq_energy_sum) AS laeq_energy, SUM(laeq_valid_ms) AS laeq_valid_ms, SUM(covered_ms) AS covered_ms')
            ->get()
            ->map(function (object $row) use ($streams, $dayStart, $elapsedEnd, $dayEnd): array {
                $stream = $streams[$row->stream_id] ?? null;

                return [
                    'stream' => $stream?->label(),
                    'calibration_state' => $stream?->calibration_state,
                    'lafmax_max' => $row->lafmax_max === null ? null : round((float) $row->lafmax_max, 1),
                    'lafmax_valid_minutes' => intdiv((int) $row->lafmax_valid_ms, 60000),
                    'laeq' => Decibels::round(Decibels::leq($row->laeq_energy === null ? null : (float) $row->laeq_energy, (int) $row->laeq_valid_ms)),
                    'laeq_valid_minutes' => intdiv((int) $row->laeq_valid_ms, 60000),
                    'elapsed_minutes' => max(0, intdiv($elapsedEnd->getTimestamp() - $dayStart->getTimestamp(), 60)),
                    'day_minutes' => intdiv($dayEnd->getTimestamp() - $dayStart->getTimestamp(), 60),
                ];
            })
            ->all();

        $staleAfter = $device->staleAfterSeconds();
        $captureAge = $latestCaptured ? $latestCaptured->diffInSeconds($now) : null;

        return [
            'device' => $device,
            'timezone' => $timezone,
            'online' => $device->isOnline($now),
            'last_contact_at' => $device->last_contact_at,
            'latest' => $latest === null ? null : [
                'laeq' => $latest->laeq_db === null || ($latestMask & QualityPolicy::excludeAllMask()) !== 0 ? null : round((float) $latest->laeq_db, 1),
                'laeq_raw' => $latest->laeq_db === null ? null : round((float) $latest->laeq_db, 1),
                'rms_dbfs' => $latest->rms_dbfs === null ? null : round((float) $latest->rms_dbfs, 1),
                'captured_at' => $latestCaptured,
                'received_at' => CarbonImmutable::parse($latest->received_at, 'UTC'),
                'age_seconds' => $captureAge,
                'stale' => $captureAge === null || $captureAge > $staleAfter,
                'stale_after_seconds' => $staleAfter,
                'flags' => QualityFlag::fromMask($latestMask),
                'calibration_state' => $latestStream?->calibration_state,
                'channel' => $latest->channel,
            ],
            'today' => $todayMaxima,
            'heartbeat' => $heartbeat === null ? null : [
                'received_at' => $heartbeat->received_at,
                'microphone_state' => $heartbeat->microphone_state,
                'microphone_fault' => $heartbeat->microphone_state !== MicrophoneState::Ok,
                'clock_sync_state' => $heartbeat->clock_sync_state,
                'clock_offset_ms' => $heartbeat->clock_offset_ms,
                'clock_problem' => $heartbeat->hasClockProblem(),
                'free_disk_bytes' => $heartbeat->free_disk_bytes,
                'total_disk_bytes' => $heartbeat->total_disk_bytes,
                'storage_pressure' => $heartbeat->total_disk_bytes ? (1 - $heartbeat->free_disk_bytes / $heartbeat->total_disk_bytes) > 0.85 : false,
                'queued_measurement_count' => $heartbeat->queued_measurement_count,
                'pending_audio_bytes' => $heartbeat->pending_audio_bytes,
                'pending_audio_count' => $heartbeat->pending_audio_count,
                'oldest_pending_capture_at' => $heartbeat->oldest_pending_capture_at,
                'recent_dropped_intervals' => $heartbeat->recent_dropped_intervals,
                'last_capture_error' => $heartbeat->last_capture_error,
                'agent_version' => $heartbeat->agent_version,
            ],
            'config_pending' => $device->desired_config_revision !== null && $device->desired_config_revision !== $device->applied_config_revision,
            'desired_config_revision' => $device->desired_config_revision,
            'applied_config_revision' => $device->applied_config_revision,
            'unsynchronized' => $heartbeat?->clock_sync_state === ClockSyncState::Unsynchronized,
        ];
    }

    /**
     * @return array{all: int, confirmed: int, unreviewed: int}
     */
    public function eventTotals(Property $property, string $localDate, ?Device $device = null): array
    {
        [$start, $end] = LocalTime::dayRange($localDate, $property->timezone);

        $query = NoiseEvent::query()
            ->where('account_id', $property->account_id)
            ->where('property_id', $property->id)
            ->where('started_at', '>=', Rfc3339::toDatabase($start))
            ->where('started_at', '<', Rfc3339::toDatabase($end))
            ->when($device, fn ($query) => $query->where('device_id', $device->id));

        return [
            'all' => (clone $query)->count(),
            'confirmed' => (clone $query)->where('review_status', ReviewStatus::ConfirmedDisturbance)->count(),
            'unreviewed' => (clone $query)->where('review_status', ReviewStatus::Unreviewed)->count(),
            'recording_pending' => (clone $query)->whereIn('recording_state', [RecordingStatus::Pending, RecordingStatus::Uploaded, RecordingStatus::Verifying])->count(),
        ];
    }
}
