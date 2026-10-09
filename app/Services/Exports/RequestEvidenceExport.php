<?php

namespace App\Services\Exports;

use App\Enums\ExportKind;
use App\Enums\ExportStatus;
use App\Enums\RecordingStatus;
use App\Jobs\BuildExportJob;
use App\Models\EvidenceExport;
use App\Models\NoiseEvent;
use App\Models\Property;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Measurements\QualityPolicy;
use App\Support\LocalTime;
use App\Support\Rfc3339;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validates an export scope, enforces interactive limits, estimates size,
 * and freezes the selection at request time (spec §15). Later annotations,
 * revisions, or recordings never change an existing export; a new export
 * must be requested instead.
 */
class RequestEvidenceExport
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{type: string, event_uuids?: list<string>, from_date?: string, to_date?: string}  $scope
     * @param  array<string, mixed>  $options
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, Property $property, ExportKind $kind, array $scope, array $options = [], bool $ownerOverride = false, ?string $title = null): EvidenceExport
    {
        if (! $user->canExport($property->account_id)) {
            throw new AuthorizationException('You are not permitted to create exports for this account.');
        }

        if ($ownerOverride && ! $user->canManage($property->account_id)) {
            throw new AuthorizationException('Only owners may override export limits.');
        }

        $now = CarbonImmutable::now();
        [$events, $range] = $this->resolveScope($property, $scope);

        $maxEvents = (int) config('noise.exports.max_events');
        $maxDays = (int) config('noise.exports.max_days');
        $days = $range['utc_start']->diffInSeconds($range['utc_end']) / 86400;

        if (! $ownerOverride) {
            if ($events->count() > $maxEvents) {
                throw ValidationException::withMessages(['scope' => "Exports are limited to {$maxEvents} events; an owner can override this limit."]);
            }

            if ($days > $maxDays + 0.0001) {
                throw ValidationException::withMessages(['scope' => "Exports are limited to {$maxDays} days; an owner can override this limit."]);
            }
        }

        $selection = $this->freeze($property, $events, $range, $now);
        $estimated = $this->estimate($kind, $selection, $range);

        $export = DB::transaction(function () use ($user, $property, $kind, $scope, $options, $selection, $estimated, $ownerOverride, $title, $now): EvidenceExport {
            $export = EvidenceExport::query()->create([
                'account_id' => $property->account_id,
                'property_id' => $property->id,
                'requested_by' => $user->id,
                'kind' => $kind,
                'status' => ExportStatus::Pending,
                'title' => $title,
                'scope' => $scope,
                'options' => $options,
                'selection' => $selection,
                'estimated_bytes' => $estimated,
                'limits_overridden' => $ownerOverride,
                'requested_at' => $now,
            ]);

            $this->audit->record('export.requested', $export, [
                'kind' => $kind->value,
                'scope_type' => $scope['type'],
                'event_count' => count($selection['events']),
                'estimated_bytes' => $estimated,
                'limits_overridden' => $ownerOverride,
            ], user: $user);

            BuildExportJob::dispatch($export->id)->afterCommit();

            return $export;
        });

        return $export;
    }

    /**
     * Rough size estimate shown before large bundles are built.
     *
     * @param  array<string, mixed>  $selection
     * @param  array<string, mixed>  $range
     */
    public function estimate(ExportKind $kind, array $selection, array $range): int
    {
        $pdf = 200_000 + 20_000 * count($selection['events']);
        $audio = array_sum(array_map(fn (array $event): int => array_sum(array_column($event['recordings'], 'byte_size')), $selection['events']));
        $snapshots = 140 * 400 * count($selection['events']);
        $seconds = (int) $range['utc_start']->diffInSeconds($range['utc_end']);
        $csv = $selection['scope_type'] === 'range' ? $seconds * max(1, count($selection['stream_ids'])) * 160 : $snapshots;

        return match ($kind) {
            ExportKind::PdfSummary => $pdf,
            ExportKind::CsvMeasurements => $csv,
            ExportKind::EvidenceBundle => $pdf + $audio + $snapshots + 50_000,
        };
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array{0: Collection<int, NoiseEvent>, 1: array<string, mixed>}
     */
    private function resolveScope(Property $property, array $scope): array
    {
        $timezone = $property->timezone;

        if (($scope['type'] ?? null) === 'events') {
            $uuids = array_values(array_unique(array_map('strtolower', $scope['event_uuids'] ?? [])));

            if ($uuids === []) {
                throw ValidationException::withMessages(['scope.event_uuids' => 'Select at least one event.']);
            }

            $events = NoiseEvent::query()
                ->where('account_id', $property->account_id)
                ->where('property_id', $property->id)
                ->whereIn('uuid', $uuids)
                ->orderBy('started_at')
                ->get();

            if ($events->count() !== count($uuids)) {
                throw ValidationException::withMessages(['scope.event_uuids' => 'One or more selected events do not exist for this property.']);
            }

            $start = $events->min('started_at')->subSeconds((int) config('noise.events.snapshot_pre_seconds'));
            $end = $events->map(fn (NoiseEvent $event): CarbonImmutable => ($event->ended_at ?? $event->started_at)->addSeconds((int) config('noise.events.snapshot_post_seconds')))->max();

            return [$events, [
                'timezone' => $timezone,
                'utc_start' => $start,
                'utc_end' => $end,
                'local_start' => LocalTime::display($start, $timezone),
                'local_end' => LocalTime::display($end, $timezone),
            ]];
        }

        if (($scope['type'] ?? null) !== 'range') {
            throw ValidationException::withMessages(['scope.type' => 'Choose selected events or a date range.']);
        }

        $from = $scope['from_date'] ?? null;
        $to = $scope['to_date'] ?? null;

        if (! is_string($from) || ! is_string($to) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1 || $to < $from) {
            throw ValidationException::withMessages(['scope.from_date' => 'Provide a valid local date range (from ≤ to).']);
        }

        [$start] = LocalTime::dayRange($from, $timezone);
        [, $end] = LocalTime::dayRange($to, $timezone);

        $events = NoiseEvent::query()
            ->where('account_id', $property->account_id)
            ->where('property_id', $property->id)
            ->where('started_at', '>=', Rfc3339::toDatabase($start))
            ->where('started_at', '<', Rfc3339::toDatabase($end))
            ->orderBy('started_at')
            ->get();

        return [$events, [
            'timezone' => $timezone,
            'utc_start' => $start,
            'utc_end' => $end,
            'local_start' => LocalTime::display($start, $timezone),
            'local_end' => LocalTime::display($end, $timezone),
            'from_date' => $from,
            'to_date' => $to,
        ]];
    }

    /**
     * @param  Collection<int, NoiseEvent>  $events
     * @param  array<string, mixed>  $range
     * @return array<string, mixed>
     */
    private function freeze(Property $property, Collection $events, array $range, CarbonImmutable $now): array
    {
        $eventIds = $events->pluck('id')->all();

        $maxAnnotation = $eventIds === [] ? [] : DB::table('event_annotations')->whereIn('noise_event_id', $eventIds)
            ->groupBy('noise_event_id')->selectRaw('noise_event_id, MAX(id) as max_id')->pluck('max_id', 'noise_event_id')->all();
        $maxRevision = $eventIds === [] ? [] : DB::table('noise_event_revisions')->whereIn('noise_event_id', $eventIds)
            ->groupBy('noise_event_id')->selectRaw('noise_event_id, MAX(id) as max_id')->pluck('max_id', 'noise_event_id')->all();
        $snapshots = $eventIds === [] ? collect() : DB::table('event_measurement_snapshots')->whereIn('noise_event_id', $eventIds)
            ->orderBy('version')->get(['id', 'noise_event_id', 'version', 'status', 'completeness', 'content_hash'])->keyBy('noise_event_id');
        $recordings = $eventIds === [] ? collect() : DB::table('event_recordings')->whereIn('noise_event_id', $eventIds)
            ->where('status', RecordingStatus::Verified->value)->whereNull('purged_at')
            ->orderBy('segment_number')->get(['id', 'uuid', 'noise_event_id', 'segment_number', 'verified_sha256', 'verified_byte_size'])->groupBy('noise_event_id');

        $deviceIds = DB::table('devices')->where('property_id', $property->id)->pluck('id')->all();
        $streams = DB::table('measurement_streams')->whereIn('device_id', $deviceIds === [] ? [0] : $deviceIds)
            ->get(['id', 'device_deployment_id', 'measurement_profile_id', 'device_calibration_id']);
        $streamIds = $events->isNotEmpty() && ! isset($range['from_date'])
            ? $events->pluck('stream_id')->unique()->values()->all()
            : $streams->pluck('id')->all();
        $streams = $streams->whereIn('id', $streamIds);
        $configurationIds = DB::table('device_configurations')->whereIn('device_id', $deviceIds === [] ? [0] : $deviceIds)->pluck('id')->all();

        return [
            'reference_time' => Rfc3339::format($now),
            'scope_type' => isset($range['from_date']) ? 'range' : 'events',
            'policy_version' => QualityPolicy::VERSION,
            'property' => ['id' => $property->id, 'uuid' => $property->uuid, 'name' => $property->name, 'timezone' => $property->timezone],
            'range' => [
                'timezone' => $range['timezone'],
                'utc_start' => Rfc3339::format($range['utc_start']),
                'utc_end' => Rfc3339::format($range['utc_end']),
                'local_start' => $range['local_start'],
                'local_end' => $range['local_end'],
                'from_date' => $range['from_date'] ?? null,
                'to_date' => $range['to_date'] ?? null,
            ],
            'device_ids' => $deviceIds,
            'stream_ids' => array_values(array_map('intval', $streamIds)),
            'provenance' => [
                'deployment_ids' => $streams->pluck('device_deployment_id')->filter()->unique()->values()->all(),
                'profile_ids' => $streams->pluck('measurement_profile_id')->unique()->values()->all(),
                'calibration_ids' => $streams->pluck('device_calibration_id')->filter()->unique()->values()->all(),
                'configuration_ids' => $configurationIds,
            ],
            'events' => $events->map(function (NoiseEvent $event) use ($maxAnnotation, $maxRevision, $snapshots, $recordings): array {
                $snapshot = $snapshots->get($event->id);

                return [
                    'id' => $event->id,
                    'uuid' => $event->uuid,
                    'current_revision' => $event->current_revision,
                    'max_revision_row_id' => (int) ($maxRevision[$event->id] ?? 0),
                    'max_annotation_id' => (int) ($maxAnnotation[$event->id] ?? 0),
                    'snapshot' => $snapshot === null ? null : [
                        'id' => $snapshot->id,
                        'version' => $snapshot->version,
                        'status' => $snapshot->status,
                        'completeness' => $snapshot->completeness,
                        'content_hash' => $snapshot->content_hash,
                    ],
                    'recordings' => ($recordings->get($event->id) ?? collect())->map(fn ($recording): array => [
                        'id' => $recording->id,
                        'uuid' => $recording->uuid,
                        'segment_number' => $recording->segment_number,
                        'sha256' => $recording->verified_sha256,
                        'byte_size' => (int) $recording->verified_byte_size,
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }
}
