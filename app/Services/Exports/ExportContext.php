<?php

namespace App\Services\Exports;

use App\Enums\Metric;
use App\Enums\QualityFlag;
use App\Models\Device;
use App\Models\DeviceConfiguration;
use App\Models\EventAnnotation;
use App\Models\EventMeasurementSnapshot;
use App\Models\EventRecording;
use App\Models\EvidenceExport;
use App\Models\MeasurementStream;
use App\Models\NoiseEvent;
use App\Models\NoiseEventRevision;
use App\Models\Property;
use App\Services\Measurements\QualityPolicy;
use App\Support\Decibels;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Loads exactly the records captured in an export's frozen selection.
 * Annotations, revisions, recordings, and snapshots created after the
 * selection was frozen are never included.
 */
class ExportContext
{
    public Property $property;

    public string $timezone;

    /** @var array<string, mixed> */
    public array $selection;

    public CarbonImmutable $rangeStart;

    public CarbonImmutable $rangeEnd;

    public CarbonImmutable $referenceTime;

    /** @var list<array<string, mixed>> */
    public array $events = [];

    /** @var Collection<int, MeasurementStream> */
    public Collection $streams;

    /** @var Collection<int, Device> */
    public Collection $devices;

    /** @var Collection<int, DeviceConfiguration> */
    public Collection $configurations;

    /** @var list<string> */
    public array $missing = [];

    /** @var array<int, array<string, mixed>> keyed by stream id */
    public array $coverage = [];

    public static function load(EvidenceExport $export): self
    {
        $context = new self;
        $context->selection = $export->selection;
        $context->property = Property::query()->where('account_id', $export->account_id)->findOrFail($export->property_id);
        $context->timezone = $context->selection['property']['timezone'] ?? $context->property->timezone;
        $context->rangeStart = CarbonImmutable::parse($context->selection['range']['utc_start'])->utc();
        $context->rangeEnd = CarbonImmutable::parse($context->selection['range']['utc_end'])->utc();
        $context->referenceTime = CarbonImmutable::parse($context->selection['reference_time'])->utc();

        $context->devices = Device::query()->where('account_id', $export->account_id)->whereIn('id', $context->selection['device_ids'] ?: [0])->get()->keyBy('id');
        $context->streams = MeasurementStream::query()
            ->where('account_id', $export->account_id)
            ->whereIn('id', $context->selection['stream_ids'] ?: [0])
            ->with(['deployment.attachments', 'profile', 'calibration.fieldChecks', 'calibration.attachments', 'device'])
            ->orderBy('id')
            ->get()
            ->keyBy('id');
        $context->configurations = DeviceConfiguration::query()
            ->where('account_id', $export->account_id)
            ->whereIn('id', $context->selection['provenance']['configuration_ids'] ?: [0])
            ->with('acknowledgments')
            ->orderBy('device_id')->orderBy('revision')
            ->get();

        $context->loadEvents($export->account_id);
        $context->loadCoverage($export->account_id);

        return $context;
    }

    private function loadEvents(int $accountId): void
    {
        $frozen = collect($this->selection['events']);
        $models = NoiseEvent::query()->where('account_id', $accountId)->whereIn('id', $frozen->pluck('id')->all() ?: [0])->get()->keyBy('id');

        foreach ($frozen as $entry) {
            $event = $models->get($entry['id']);

            if ($event === null) {
                $this->missing[] = "Event {$entry['uuid']} was deleted after this export was requested; it is omitted.";

                continue;
            }

            $revisions = NoiseEventRevision::query()->where('noise_event_id', $event->id)->where('id', '<=', $entry['max_revision_row_id'])->orderBy('revision')->get();
            $current = $revisions->firstWhere('revision', $entry['current_revision']);
            $annotations = EventAnnotation::query()->where('noise_event_id', $event->id)->where('id', '<=', $entry['max_annotation_id'])->with('author')->orderBy('id')->get();
            $review = $annotations->where('kind', EventAnnotation::KIND_REVIEW)->last();
            $snapshot = $entry['snapshot'] === null ? null : EventMeasurementSnapshot::query()->where('noise_event_id', $event->id)->find($entry['snapshot']['id']);
            $recordingIds = array_column($entry['recordings'], 'id');
            $recordings = EventRecording::query()->where('noise_event_id', $event->id)->whereIn('id', $recordingIds ?: [0])->orderBy('segment_number')->get();

            if ($entry['snapshot'] === null) {
                $this->missing[] = "Event {$event->uuid}: no one-second measurement snapshot existed when the export was requested.";
            } elseif ($snapshot === null) {
                $this->missing[] = "Event {$event->uuid}: the frozen measurement snapshot was deleted before the export was built.";
            } elseif ($snapshot->completeness->value !== 'complete') {
                $this->missing[] = "Event {$event->uuid}: measurement snapshot v{$snapshot->version} is {$snapshot->completeness->value}".($snapshot->limitations ? ' — '.$snapshot->limitations : '.');
            }

            if ($event->recording_expected && $entry['recordings'] === []) {
                $this->missing[] = "Event {$event->uuid}: a recording was expected but no verified recording existed when the export was requested (state: ".($event->recording_state?->value ?? 'unknown').').';
            }

            $payload = $current?->payload;

            $this->events[] = [
                'model' => $event,
                'frozen' => $entry,
                'payload' => $payload,
                'revisions' => $revisions,
                'annotations' => $annotations,
                'review' => $review,
                'snapshot' => $snapshot,
                'recordings' => $recordings,
                'device' => $this->devices->get($event->device_id) ?? $event->device,
                'stream' => $this->streams->get($event->stream_id) ?? $event->stream,
                'server' => $snapshot ? $this->summarizeSnapshot($snapshot, $payload) : null,
            ];
        }
    }

    /**
     * Server-derived summary over the detection window from the frozen snapshot.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    public function summarizeSnapshot(EventMeasurementSnapshot $snapshot, ?array $payload): array
    {
        $start = $payload ? CarbonImmutable::parse($payload['started_at'])->getTimestamp() : $snapshot->detection_start->getTimestamp();
        $endValue = $payload['ended_at'] ?? null;
        $end = $endValue ? CarbonImmutable::parse($endValue)->getTimestamp() : null;
        $energy = 0.0;
        $energyMs = 0;
        $max = null;
        $measured = 0;

        foreach ($snapshot->decodedRows() as $row) {
            $second = CarbonImmutable::parse($row['captured_at'])->getTimestamp();

            if ($second < $start || ($end !== null && $second >= $end)) {
                continue;
            }

            $measured++;
            $mask = QualityFlag::toMask($row['quality_flags']);

            if ($row['laeq_db'] !== null && ! QualityPolicy::excludes(Metric::LAeq, $mask)) {
                $energy += Decibels::energy($row['laeq_db'], $row['duration_ms']);
                $energyMs += $row['duration_ms'];
            }

            if ($row['lafmax_db'] !== null && ! QualityPolicy::excludes(Metric::LAFmax, $mask)) {
                $max = max($max ?? -INF, $row['lafmax_db']);
            }
        }

        return [
            'laeq_db' => Decibels::leq($energy, $energyMs),
            'laeq_valid_ms' => $energyMs,
            'lafmax_db' => $max,
            'measured_ms' => $measured * 1000,
            'expected_ms' => $end === null ? null : max(0, ($end - $start) * 1000),
        ];
    }

    private function loadCoverage(int $accountId): void
    {
        $rows = DB::table('measurement_rollups')
            ->where('account_id', $accountId)
            ->whereIn('stream_id', $this->streams->keys()->all() ?: [0])
            ->where('resolution_seconds', 60)
            ->where('bucket_start', '>=', $this->rangeStart->startOfMinute()->format('Y-m-d H:i:s'))
            ->where('bucket_start', '<', $this->rangeEnd->format('Y-m-d H:i:s'))
            ->groupBy('stream_id')
            ->selectRaw('stream_id, SUM(laeq_energy_sum) as laeq_energy, SUM(laeq_valid_ms) as laeq_valid_ms, SUM(laeq_excluded_ms) as laeq_excluded_ms, MAX(lafmax_max) as lafmax_max, SUM(dbfs_energy_sum) as dbfs_energy, SUM(dbfs_valid_ms) as dbfs_valid_ms, SUM(covered_ms) as covered_ms, SUM(ambiguous_ms) as ambiguous_ms, COUNT(*) as buckets')
            ->get()
            ->keyBy('stream_id');

        $expectedMs = ($this->rangeEnd->getTimestamp() - $this->rangeStart->getTimestamp()) * 1000;

        foreach ($this->streams as $stream) {
            $row = $rows->get($stream->id);
            $absolute = $stream->calibration_state->allowsAbsoluteLevels();

            $this->coverage[$stream->id] = [
                'expected_ms' => $expectedMs,
                'metric' => $absolute ? 'LAeq' : 'RMS level (dBFS)',
                'unit' => $absolute ? 'dBA' : 'dBFS',
                'valid_ms' => (int) ($absolute ? ($row->laeq_valid_ms ?? 0) : ($row->dbfs_valid_ms ?? 0)),
                'excluded_ms' => (int) ($row->laeq_excluded_ms ?? 0),
                'ambiguous_ms' => (int) ($row->ambiguous_ms ?? 0),
                'leq' => $row === null ? null : Decibels::leq((float) ($absolute ? $row->laeq_energy : $row->dbfs_energy), (int) ($absolute ? $row->laeq_valid_ms : $row->dbfs_valid_ms)),
                'lafmax_max' => $absolute && $row?->lafmax_max !== null ? (float) $row->lafmax_max : null,
            ];

            if ($this->selection['scope_type'] === 'range' && $this->coverage[$stream->id]['valid_ms'] < $expectedMs) {
                $this->missing[] = sprintf(
                    'Stream %s: valid data covers %s of %s minutes in the requested range; missing time is unknown, not silence.',
                    $stream->label(),
                    number_format($this->coverage[$stream->id]['valid_ms'] / 60000, 1),
                    number_format($expectedMs / 60000, 0),
                );
            }
        }
    }
}
