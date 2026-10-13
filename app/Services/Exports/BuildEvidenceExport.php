<?php

namespace App\Services\Exports;

use App\Enums\ExportKind;
use App\Enums\ExportStatus;
use App\Enums\Metric;
use App\Enums\QualityFlag;
use App\Models\Attachment;
use App\Models\EvidenceExport;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Measurements\QualityPolicy;
use App\Services\Storage\EvidenceStorage;
use App\Support\CsvEscaper;
use App\Support\Decibels;
use App\Support\LocalTime;
use App\Support\Rfc3339;
use Barryvdh\DomPDF\PDF;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Builds a PDF summary, CSV measurement export, or ZIP evidence bundle from
 * an export's frozen selection (spec §15). Source objects are only read,
 * never modified. A ready export is never rebuilt; a partially built export
 * is rebuilt to the same object key.
 */
class BuildEvidenceExport
{
    public const HASH_STATEMENT = 'SHA-256 values show whether exported bytes match the bytes this server retained. They do not prove capture authenticity, a correct device clock, or a particular noise source. Application audit logs are traceability features, not a tamper-proof chain of custody.';

    /** CSV value for readings taken before any configuration was published (the agent's local defaults). */
    public const LOCAL_DEFAULTS = 'local-defaults';

    public const DISCLAIMER = 'This report was produced by a do-it-yourself home noise monitoring system (Raspberry Pi with a consumer measurement microphone). It is not a certified or regulatory sound level meter, and these readings do not by themselves establish a legal violation, identify a particular vehicle, or identify a particular person.';

    public function __construct(
        private readonly EvidenceStorage $storage,
        private readonly SvgChart $charts,
        private readonly AuditLogger $audit,
    ) {}

    public function build(EvidenceExport $export): EvidenceExport
    {
        $export = EvidenceExport::query()->findOrFail($export->id);

        if (in_array($export->status, [ExportStatus::Ready, ExportStatus::Expired], true)) {
            return $export;
        }

        $requester = $export->requested_by ? User::query()->find($export->requested_by) : null;

        if ($requester === null || ! $requester->canExport($export->account_id)) {
            $this->markFailed($export, 'The requester no longer has export permission for this account.');

            return $export->fresh();
        }

        EvidenceExport::query()->whereKey($export->id)->update([
            'status' => ExportStatus::Building->value,
            'started_at' => $export->started_at ?? CarbonImmutable::now(),
            'attempts' => DB::raw('attempts + 1'),
            'failure_reason' => null,
        ]);

        $directory = storage_path('app/export-work/'.$export->uuid);
        File::deleteDirectory($directory);
        File::ensureDirectoryExists($directory);

        try {
            $context = ExportContext::load($export);
            $generatedAt = CarbonImmutable::now();

            [$path, $fileName, $manifest] = match ($export->kind) {
                ExportKind::PdfSummary => $this->pdfOnly($export, $context, $directory, $generatedAt),
                ExportKind::CsvMeasurements => $this->csvOnly($export, $context, $directory),
                ExportKind::EvidenceBundle => $this->bundle($export, $context, $directory, $generatedAt),
            };

            $this->finalize($export, $path, $fileName, $manifest);
        } catch (Throwable $exception) {
            $this->markFailed($export, Str::limit($exception->getMessage(), 1000));

            throw $exception;
        } finally {
            File::deleteDirectory($directory);
        }

        return $export->fresh();
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function pdfOnly(EvidenceExport $export, ExportContext $context, string $directory, CarbonImmutable $generatedAt): array
    {
        $path = $directory.'/summary.pdf';
        file_put_contents($path, $this->renderPdf($export, $context, $generatedAt));

        return [$path, $this->fileName($export, $context, 'pdf'), $this->baseManifest($export, $context, $generatedAt)];
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function csvOnly(EvidenceExport $export, ExportContext $context, string $directory): array
    {
        $path = $directory.'/measurements.csv';
        $this->writeMeasurementsCsv($context, $path);

        return [$path, $this->fileName($export, $context, 'csv'), $this->baseManifest($export, $context, CarbonImmutable::now())];
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function bundle(EvidenceExport $export, ExportContext $context, string $directory, CarbonImmutable $generatedAt): array
    {
        $files = [];
        $root = $directory.'/bundle';
        File::ensureDirectoryExists($root);

        $add = function (string $relative, string $contents, array $source, string $mediaType) use ($root, &$files): void {
            $target = $root.'/'.$relative;
            File::ensureDirectoryExists(dirname($target));
            file_put_contents($target, $contents);
            $files[$relative] = ['path' => $relative, 'bytes' => strlen($contents), 'sha256' => hash('sha256', $contents), 'media_type' => $mediaType, 'source' => $source];
        };

        $add('summary.pdf', $this->renderPdf($export, $context, $generatedAt), ['type' => 'generated_report'], 'application/pdf');
        $add('events.csv', $this->eventsCsv($context), ['type' => 'event_summary'], 'text/csv');

        foreach ($context->events as $item) {
            $event = $item['model'];
            $base = 'events/'.$event->uuid;

            $add($base.'/revisions.json', $this->json($item['revisions']->map(fn ($revision): array => [
                'revision' => $revision->revision,
                'received_at' => Rfc3339::format($revision->received_at),
                'payload_sha256' => $revision->payload_hash,
                'applied_to_projection' => $revision->applied_to_projection,
                'payload' => $revision->payload,
            ])->values()->all()), ['type' => 'noise_event_revisions', 'event_id' => $event->uuid], 'application/json');

            $add($base.'/annotations.json', $this->json($item['annotations']->map(fn ($annotation): array => [
                'id' => $annotation->uuid,
                'kind' => $annotation->kind,
                'review_status' => $annotation->review_status?->value,
                'source_label' => $annotation->source_label?->value,
                'source_certainty' => $annotation->source_certainty?->value,
                'confidence' => $annotation->confidence?->value,
                'notes' => $annotation->notes,
                'metadata' => $annotation->metadata,
                'supersedes_id' => $annotation->supersedes?->uuid,
                'author' => $annotation->author?->name,
                'created_at' => Rfc3339::format($annotation->created_at),
            ])->values()->all()), ['type' => 'event_annotations', 'event_id' => $event->uuid], 'application/json');

            if ($item['snapshot'] !== null) {
                $snapshot = $item['snapshot'];
                $add($base.'/measurements-snapshot.json', $this->json([
                    'snapshot_id' => $snapshot->uuid,
                    'version' => $snapshot->version,
                    'resolution' => 'PT1S (one-second measurements as reported by the device)',
                    'status' => $snapshot->status,
                    'completeness' => $snapshot->completeness->value,
                    'window_start' => Rfc3339::format($snapshot->window_start),
                    'window_end' => Rfc3339::format($snapshot->window_end),
                    'detection_start' => Rfc3339::format($snapshot->detection_start),
                    'detection_end' => Rfc3339::format($snapshot->detection_end),
                    'expected_intervals' => $snapshot->expected_intervals,
                    'captured_intervals' => $snapshot->captured_intervals,
                    'missing_ranges' => $snapshot->missing_ranges ?? [],
                    'limitations' => $snapshot->limitations,
                    'quality_policy_version' => $snapshot->policy_version,
                    'rows_sha256' => $snapshot->content_hash,
                    'rows' => $snapshot->decodedRows(),
                ]), ['type' => 'event_measurement_snapshot', 'event_id' => $event->uuid, 'snapshot_id' => $snapshot->uuid], 'application/json');
            } else {
                $add($base.'/measurements-rollups-PT1M.json', $this->json([
                    'resolution' => 'PT1M (coarser historical minute rollups; one-second readings were not available for this event)',
                    'rows' => $this->rollupRowsFor($item),
                ]), ['type' => 'measurement_rollups', 'event_id' => $event->uuid], 'application/json');
            }

            foreach ($item['recordings'] as $recording) {
                $relative = sprintf('%s/audio/segment-%02d-%s.%s', $base, $recording->segment_number, $recording->uuid, $recording->fileExtension());

                if (! $recording->isPlayable()) {
                    $context->missing[] = "Recording {$recording->uuid} (event {$event->uuid}) was purged or became unavailable after the export was requested.";

                    continue;
                }

                try {
                    $download = $this->storage->downloadAndHash($recording->final_key);
                } catch (Throwable $exception) {
                    $context->missing[] = "Recording {$recording->uuid}: the preserved original could not be read from storage.";

                    continue;
                }

                if (! hash_equals((string) $recording->verified_sha256, $download['sha256'])) {
                    @unlink($download['path']);
                    $context->missing[] = "Recording {$recording->uuid}: stored bytes no longer match the verified SHA-256; the file was not included. Investigate storage integrity.";

                    continue;
                }

                $target = $root.'/'.$relative;
                File::ensureDirectoryExists(dirname($target));
                rename($download['path'], $target);
                $files[$relative] = [
                    'path' => $relative,
                    'bytes' => $download['bytes'],
                    'sha256' => $download['sha256'],
                    'media_type' => $recording->mime_type,
                    'source' => [
                        'type' => 'verified_original_recording',
                        'recording_id' => $recording->uuid,
                        'event_id' => $event->uuid,
                        'segment_number' => $recording->segment_number,
                        'device_reported_sha256' => $recording->reported_sha256,
                        'capture_started_at' => Rfc3339::format($recording->capture_started_at),
                        'duration_ms' => $recording->verified_duration_ms ?? $recording->duration_ms,
                        'verified_at' => Rfc3339::format($recording->verified_at),
                    ],
                ];
            }
        }

        foreach ($this->provenanceDocuments($context) as $name => $document) {
            $add('provenance/'.$name.'.json', $this->json($document), ['type' => 'provenance_'.$name], 'application/json');
        }

        foreach ($this->attachments($context) as $attachment) {
            $relative = 'provenance/attachments/'.$attachment->uuid.'-'.$this->safeName($attachment->original_filename);

            try {
                $download = $this->storage->downloadAndHash($attachment->object_key);
            } catch (Throwable) {
                $context->missing[] = "Supporting file {$attachment->original_filename} ({$attachment->uuid}) could not be read from storage.";

                continue;
            }

            if (! hash_equals($attachment->sha256, $download['sha256'])) {
                @unlink($download['path']);
                $context->missing[] = "Supporting file {$attachment->uuid}: stored bytes do not match the recorded SHA-256; not included.";

                continue;
            }

            File::ensureDirectoryExists(dirname($root.'/'.$relative));
            rename($download['path'], $root.'/'.$relative);
            $files[$relative] = ['path' => $relative, 'bytes' => $download['bytes'], 'sha256' => $download['sha256'], 'media_type' => $attachment->mime_type, 'source' => ['type' => 'attachment', 'attachment_id' => $attachment->uuid, 'purpose' => $attachment->purpose]];
        }

        $add('README.txt', $this->readme($export, $context), ['type' => 'generated_readme'], 'text/plain');

        ksort($files, SORT_STRING);
        $manifest = $this->baseManifest($export, $context, $generatedAt);
        $manifest['files'] = array_values($files);
        $manifestJson = $this->json($manifest);
        file_put_contents($root.'/manifest.json', $manifestJson);

        $zipPath = $directory.'/bundle.zip';
        $this->zip($root, array_merge(array_keys($files), ['manifest.json']), $zipPath, $context->referenceTime);

        return [$zipPath, $this->fileName($export, $context, 'zip'), $manifest];
    }

    /**
     * @param  list<string>  $entries
     */
    private function zip(string $root, array $entries, string $zipPath, CarbonImmutable $mtime): void
    {
        sort($entries, SORT_STRING);
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create ZIP archive.');
        }

        foreach ($entries as $entry) {
            $zip->addFile($root.'/'.$entry, $entry);
            $zip->setMtimeName($entry, $mtime->getTimestamp());

            if (preg_match('/\.(flac|pdf)$/', $entry) === 1) {
                $zip->setCompressionName($entry, ZipArchive::CM_STORE);
            }
        }

        if (! $zip->close()) {
            throw new RuntimeException('Could not write ZIP archive.');
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function finalize(EvidenceExport $export, string $path, string $fileName, array $manifest): void
    {
        $key = sprintf('exports/%s/%s/%s', $export->account->uuid, $export->uuid, $fileName);
        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);
        $stream = fopen($path, 'rb');

        try {
            $this->storage->disk()->writeStream($key, $stream, ['ContentType' => match (pathinfo($fileName, PATHINFO_EXTENSION)) {
                'zip' => 'application/zip',
                'pdf' => 'application/pdf',
                default => 'text/csv',
            }]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ((int) $this->storage->disk()->size($key) !== $bytes) {
            throw new RuntimeException('Uploaded export size does not match the built file.');
        }

        $now = CarbonImmutable::now();
        $updated = EvidenceExport::query()
            ->whereKey($export->id)
            ->where('status', '!=', ExportStatus::Ready->value)
            ->update([
                'status' => ExportStatus::Ready->value,
                'manifest' => json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                'byte_size' => $bytes,
                'export_sha256' => $sha256,
                'disk' => $this->storage->diskName(),
                'object_key' => $key,
                'file_name' => $fileName,
                'finished_at' => $now,
                'expires_at' => $now->addDays((int) ($export->account->retention('exports_days') ?? config('noise.exports.expiry_days'))),
                'failure_reason' => null,
            ]);

        if ($updated === 1) {
            $this->audit->record('export.ready', $export->fresh(), ['sha256' => $sha256, 'byte_size' => $bytes, 'kind' => $export->kind->value]);
        }
    }

    private function markFailed(EvidenceExport $export, string $reason): void
    {
        EvidenceExport::query()->whereKey($export->id)->where('status', '!=', ExportStatus::Ready->value)->update([
            'status' => ExportStatus::Failed->value,
            'failure_reason' => $reason,
            'finished_at' => CarbonImmutable::now(),
        ]);

        $this->audit->record('export.failed', $export, ['reason' => $reason]);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseManifest(EvidenceExport $export, ExportContext $context, CarbonImmutable $generatedAt): array
    {
        return [
            'format' => 'noise-monitor-evidence-manifest/1',
            'export_id' => $export->uuid,
            'kind' => $export->kind->value,
            'property' => ['id' => $context->property->uuid, 'name' => $context->property->name, 'timezone' => $context->timezone],
            'selection_frozen_at' => Rfc3339::format($context->referenceTime),
            'generated_at' => Rfc3339::format($generatedAt),
            'requested_range' => $context->selection['range'],
            'timestamps' => 'All machine-readable timestamps are RFC 3339 UTC. Local times use '.$context->timezone.' and include the UTC offset.',
            'event_ids' => array_map(fn (array $item): string => $item['model']->uuid, $context->events),
            'quality_policy' => QualityPolicy::describe(),
            'processing_versions' => $context->streams->map(fn ($stream): array => [
                'stream' => $stream->label(),
                'profile_id' => $stream->profile?->uuid,
                'agent_processing_version' => $stream->profile?->agent_processing_version,
                'weighting_implementation_version' => $stream->profile?->weighting_implementation_version,
                'filter_implementation_version' => $stream->profile?->filter_implementation_version,
                'calibration_state' => $stream->calibration_state->value,
            ])->values()->all(),
            'missing_data' => array_values(array_unique($context->missing)),
            'hash_algorithm' => 'SHA-256',
            'hash_statement' => self::HASH_STATEMENT,
            'disclaimer' => self::DISCLAIMER,
            'references' => 'Events, recordings, and provenance are referenced by persistent identifiers, never by URLs.',
        ];
    }

    public function renderPdf(EvidenceExport $export, ExportContext $context, CarbonImmutable $generatedAt): string
    {
        $html = view('exports.summary', $this->reportData($export, $context, $generatedAt))->render();

        /** @var PDF $pdf */
        $pdf = app('dompdf.wrapper');
        $pdf->setOption([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isFontSubsettingEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
        ]);
        $pdf->setPaper('letter', 'portrait');
        $pdf->loadHTML($html);

        return $pdf->output();
    }

    /**
     * View data for the PDF summary; all user-supplied text is escaped by Blade.
     *
     * @return array<string, mixed>
     */
    public function reportData(EvidenceExport $export, ExportContext $context, CarbonImmutable $generatedAt): array
    {
        $tz = $context->timezone;
        $events = [];
        $notes = [];
        $flags = [];

        foreach ($context->events as $item) {
            $event = $item['model'];
            $payload = $item['payload'] ?? [];
            $review = $item['review'];
            $absolute = $item['stream']?->calibration_state->allowsAbsoluteLevels() ?? true;
            $started = isset($payload['started_at']) ? CarbonImmutable::parse($payload['started_at']) : $event->started_at;
            $ended = ! empty($payload['ended_at']) ? CarbonImmutable::parse($payload['ended_at']) : null;

            foreach ($payload['quality_flags'] ?? [] as $flag) {
                $flags[$flag] = ($flags[$flag] ?? 0) + 1;
            }

            $events[] = [
                'uuid' => $event->uuid,
                'short' => Str::substr($event->uuid, 0, 8),
                'local_start' => $started->setTimezone($tz)->format('Y-m-d H:i:s T'),
                'utc_start' => Rfc3339::format($started),
                'duration' => $ended ? number_format(($ended->getTimestampMs() - $started->getTimestampMs()) / 1000, 1).' s' : 'open (no end reported)',
                'device' => $item['device']?->name,
                'trigger' => isset($payload['detection']) ? sprintf('%s %s', Metric::tryFrom($payload['detection']['trigger_metric'])?->getLabel() ?? $payload['detection']['trigger_metric'], $payload['detection']['trigger_value_db'] === null ? '—' : number_format($payload['detection']['trigger_value_db'], 1).' '.Metric::tryFrom($payload['detection']['trigger_metric'])?->unit()) : '—',
                'baseline' => isset($payload['detection']['baseline_db']) && $payload['detection']['baseline_db'] !== null && $payload['detection']['trigger_value_db'] !== null
                    ? number_format($payload['detection']['trigger_value_db'] - $payload['detection']['baseline_db'], 1).' dB above reported baseline ('.number_format($payload['detection']['baseline_db'], 1).')'
                    : '—',
                'agent_laeq' => $this->db($payload['summary']['laeq_db'] ?? null),
                'agent_lafmax' => $this->db($payload['summary']['lafmax_db'] ?? null),
                'server_laeq' => $absolute ? $this->db($item['server']['laeq_db'] ?? null) : 'n/a (uncalibrated)',
                'server_lafmax' => $absolute ? $this->db($item['server']['lafmax_db'] ?? null) : 'n/a (uncalibrated)',
                'review' => $review?->review_status?->getLabel() ?? 'Unreviewed',
                'source' => $review?->source_label ? $review->source_label->getLabel().($review->source_certainty ? ' ('.strtolower($review->source_certainty->getLabel()).')' : '').($review->confidence ? ', '.strtolower($review->confidence->getLabel()).' confidence' : '') : '—',
                'completeness' => $item['snapshot']?->completeness->getLabel() ?? 'No snapshot',
                'recordings' => count($item['recordings']).' verified',
                'flags' => implode(', ', $payload['quality_flags'] ?? []),
            ];

            foreach ($item['annotations'] as $annotation) {
                if (filled($annotation->notes)) {
                    $notes[] = [
                        'event' => Str::substr($event->uuid, 0, 8),
                        'author' => $annotation->author?->name ?? 'Unknown',
                        'at' => LocalTime::display($annotation->created_at, $tz, false),
                        'kind' => $annotation->kind,
                        'text' => $this->sanitizeNote($annotation->notes),
                    ];
                }
            }
        }

        return [
            'export' => $export,
            'title' => $export->title ?: 'Noise monitoring summary',
            'property' => $context->property,
            'timezone' => $tz,
            'range' => $context->selection['range'],
            'scopeType' => $context->selection['scope_type'],
            'generatedLocal' => LocalTime::display($generatedAt, $tz),
            'generatedUtc' => Rfc3339::format($generatedAt),
            'frozenUtc' => Rfc3339::format($context->referenceTime),
            'equipment' => $context->streams->map(fn ($stream): array => [
                'label' => $stream->label(),
                'device' => $stream->device?->name,
                'microphone' => trim(($stream->profile?->microphone_model ?? '').' '.($stream->profile?->microphone_serial ? 'S/N '.$stream->profile->microphone_serial : '')),
                'interface' => $stream->profile?->audio_interface,
                'sample_rate' => $stream->profile?->sample_rate_hz,
                'gain' => $stream->profile?->gain_db !== null ? $stream->profile->gain_db.' dB' : ($stream->profile?->gain_description ?? '—'),
                'placement' => $stream->deployment ? $stream->deployment->label().($stream->deployment->placement_description ? ' — '.$stream->deployment->placement_description : '') : 'Placement not recorded',
                'mounting' => $stream->deployment?->mounting_notes,
                'calibration_state' => $stream->calibration_state,
                'calibration' => $stream->calibration ? $stream->calibration->reference_method.($stream->calibration->reference_level_db ? ', reference '.$stream->calibration->reference_level_db.' dB' : '').($stream->calibration->performed_at ? ', performed '.LocalTime::display($stream->calibration->performed_at, $tz, false) : '') : 'No calibration record (uncalibrated: digital dBFS only)',
                'field_checks' => $stream->calibration?->fieldChecks->count() ?? 0,
                'lf_band' => $stream->profile?->lowFrequencyBandLabel(),
                'versions' => 'agent '.$stream->profile?->agent_processing_version.', weighting '.$stream->profile?->weighting_implementation_version.', filters '.$stream->profile?->filter_implementation_version,
            ])->values()->all(),
            'coverage' => $context->streams->map(fn ($stream): array => [
                'label' => $stream->label(),
                ...$context->coverage[$stream->id],
            ])->values()->all(),
            'events' => $events,
            'notes' => $notes,
            'flags' => $flags,
            'charts' => $this->charts($context),
            'missing' => array_values(array_unique($context->missing)),
            'disclaimer' => self::DISCLAIMER,
            'hashStatement' => self::HASH_STATEMENT,
            'policy' => QualityPolicy::describe(),
        ];
    }

    /**
     * @return list<array{title: string, uri: string}>
     */
    private function charts(ExportContext $context): array
    {
        $charts = [];

        if ($context->selection['scope_type'] === 'range') {
            $span = $context->rangeEnd->getTimestamp() - $context->rangeStart->getTimestamp();
            $resolution = $span > 2 * 86400 ? 3600 : 60;

            foreach ($context->streams->take(3) as $stream) {
                $absolute = $stream->calibration_state->allowsAbsoluteLevels();
                $rows = DB::table('measurement_rollups')
                    ->where('stream_id', $stream->id)
                    ->where('resolution_seconds', $resolution)
                    ->where('bucket_start', '>=', $context->rangeStart->format('Y-m-d H:i:s'))
                    ->where('bucket_start', '<', $context->rangeEnd->format('Y-m-d H:i:s'))
                    ->orderBy('bucket_start')
                    ->get(['bucket_start', 'laeq_energy_sum', 'laeq_valid_ms', 'lafmax_max', 'dbfs_energy_sum', 'dbfs_valid_ms']);

                $eq = [];
                $max = [];

                foreach ($rows as $row) {
                    $ts = CarbonImmutable::parse($row->bucket_start, 'UTC')->getTimestamp();
                    $eq[] = [$ts, $absolute ? Decibels::leq($row->laeq_energy_sum === null ? null : (float) $row->laeq_energy_sum, (int) $row->laeq_valid_ms) : Decibels::leq($row->dbfs_energy_sum === null ? null : (float) $row->dbfs_energy_sum, (int) $row->dbfs_valid_ms)];
                    $max[] = [$ts, $absolute && $row->lafmax_max !== null ? (float) $row->lafmax_max : null];
                }

                $series = [['label' => ($absolute ? 'LAeq' : 'RMS level').' per '.($resolution === 60 ? 'minute' : 'hour').' (energy average)', 'color' => '#2563eb', 'points' => $eq]];

                if ($absolute) {
                    $series[] = ['label' => 'Maximum LAFmax in bucket', 'color' => '#dc2626', 'points' => $max, 'dashed' => true];
                }

                $charts[] = [
                    'title' => $stream->label(),
                    'uri' => $this->charts->dataUri($this->charts->render($series, $context->rangeStart->getTimestamp(), $context->rangeEnd->getTimestamp(), $resolution, $context->timezone, $absolute ? 'dBA' : 'dBFS', title: ($absolute ? 'LAeq and LAFmax' : 'RMS level (dBFS)').' — '.$stream->device?->name)),
                ];
            }

            return $charts;
        }

        foreach (array_slice($context->events, 0, 12) as $item) {
            $snapshot = $item['snapshot'];

            if ($snapshot === null) {
                continue;
            }

            $absolute = $item['stream']?->calibration_state->allowsAbsoluteLevels() ?? true;
            $eq = [];
            $max = [];

            foreach ($snapshot->decodedRows() as $row) {
                $ts = CarbonImmutable::parse($row['captured_at'])->getTimestamp();
                $mask = QualityFlag::toMask($row['quality_flags']);
                $metric = $absolute ? Metric::LAeq : Metric::RmsDbfs;
                $value = $row[$metric->value];
                $eq[] = [$ts, $value !== null && ! QualityPolicy::excludes($metric, $mask) ? (float) $value : null];
                $max[] = [$ts, $absolute && $row['lafmax_db'] !== null && ! QualityPolicy::excludes(Metric::LAFmax, $mask) ? (float) $row['lafmax_db'] : null];
            }

            $series = [['label' => $absolute ? 'LAeq (1 s)' : 'RMS level dBFS (1 s)', 'color' => '#2563eb', 'points' => $eq]];

            if ($absolute) {
                $series[] = ['label' => 'LAFmax (1 s interval maximum)', 'color' => '#dc2626', 'points' => $max, 'dashed' => true];
            }

            $from = $snapshot->window_start->getTimestamp();
            $to = ($snapshot->window_end ?? $snapshot->window_start->addSeconds(max(60, $snapshot->expected_intervals)))->getTimestamp();
            $highlightEnd = ($snapshot->detection_end ?? $snapshot->window_end ?? $snapshot->detection_start)->getTimestamp();

            $charts[] = [
                'title' => 'Event '.Str::substr($item['model']->uuid, 0, 8).' — shaded band is the detection window',
                'uri' => $this->charts->dataUri($this->charts->render($series, $from, $to, 1, $context->timezone, $absolute ? 'dBA' : 'dBFS', 720, 200, highlight: [$snapshot->detection_start->getTimestamp(), $highlightEnd])),
            ];
        }

        return $charts;
    }

    private function eventsCsv(ExportContext $context): string
    {
        $csv = CsvEscaper::line([
            'event_id', 'device', 'channel', 'started_at_utc', 'started_at_local', 'ended_at_utc', 'duration_ms', 'detection_state', 'current_revision',
            'trigger_metric', 'trigger_value_db', 'threshold_db', 'baseline_db', 'baseline_method', 'agent_laeq_db', 'agent_lafmax_db',
            'server_laeq_db', 'server_lafmax_db', 'server_measured_ms', 'review_status', 'source_label', 'source_certainty', 'confidence',
            'snapshot_completeness', 'recording_state', 'verified_recordings', 'quality_flags', 'keep',
        ]);

        foreach ($context->events as $item) {
            $event = $item['model'];
            $payload = $item['payload'] ?? [];
            $review = $item['review'];
            $started = isset($payload['started_at']) ? CarbonImmutable::parse($payload['started_at']) : $event->started_at;
            $ended = ! empty($payload['ended_at']) ? CarbonImmutable::parse($payload['ended_at']) : null;

            $csv .= CsvEscaper::line([
                $event->uuid,
                $item['device']?->name,
                $event->channel,
                Rfc3339::format($started),
                LocalTime::display($started, $context->timezone),
                Rfc3339::format($ended),
                $ended ? $ended->getTimestampMs() - $started->getTimestampMs() : null,
                $payload['detection_state'] ?? $event->detection_state->value,
                $item['frozen']['current_revision'],
                $payload['detection']['trigger_metric'] ?? null,
                $payload['detection']['trigger_value_db'] ?? null,
                $payload['detection']['threshold_db'] ?? null,
                $payload['detection']['baseline_db'] ?? null,
                $payload['detection']['baseline_method'] ?? null,
                $payload['summary']['laeq_db'] ?? null,
                $payload['summary']['lafmax_db'] ?? null,
                isset($item['server']['laeq_db']) ? round($item['server']['laeq_db'], 2) : null,
                $item['server']['lafmax_db'] ?? null,
                $item['server']['measured_ms'] ?? null,
                $review?->review_status?->value ?? 'unreviewed',
                $review?->source_label?->value,
                $review?->source_certainty?->value,
                $review?->confidence?->value,
                $item['snapshot']?->completeness->value,
                $event->recording_state?->value,
                count($item['recordings']),
                implode(' ', $payload['quality_flags'] ?? []),
                $event->keep,
            ]);
        }

        return $csv;
    }

    /**
     * One-second rows where retained; explicitly labelled minute rollups for
     * earlier time whose raw readings were removed by retention.
     */
    public function writeMeasurementsCsv(ExportContext $context, string $path): void
    {
        $handle = fopen($path, 'wb');
        fwrite($handle, CsvEscaper::line([
            'resolution', 'interval_start_utc', 'interval_end_utc', 'interval_start_local', 'event_id', 'device', 'channel', 'boot_id', 'sequence',
            'deployment_id', 'profile_id', 'calibration_id', 'calibration_state', 'configuration_revisions', 'laeq_db', 'lafmax_db', 'lceq_db',
            'lcpeak_db', 'low_frequency_leq_db', 'rms_dbfs', 'valid_ms', 'excluded_ms', 'quality_flags', 'null_reasons', 'row_sha256',
        ]));

        if ($context->selection['scope_type'] === 'events') {
            foreach ($context->events as $item) {
                foreach ($item['snapshot']?->decodedRows() ?? [] as $row) {
                    $start = CarbonImmutable::parse($row['captured_at']);
                    fwrite($handle, CsvEscaper::line([
                        'PT1S', Rfc3339::format($start), Rfc3339::format($start->addMilliseconds($row['duration_ms'])), LocalTime::display($start, $context->timezone),
                        $item['model']->uuid, $item['device']?->name, $row['channel'], $row['boot_id'], $row['sequence'], $row['deployment_id'], $row['profile_id'],
                        $row['calibration_id'], $row['calibration_state'], $row['configuration_revision'] ?? self::LOCAL_DEFAULTS, $row['laeq_db'], $row['lafmax_db'], $row['lceq_db'],
                        $row['lcpeak_db'], $row['low_frequency_leq_db'], $row['rms_dbfs'], $row['duration_ms'], null, implode(' ', $row['quality_flags']),
                        json_encode($row['null_reasons']), $row['row_sha256'],
                    ]));
                }
            }

            fclose($handle);

            return;
        }

        foreach ($context->streams as $stream) {
            $oldestRaw = DB::table('measurements')->where('stream_id', $stream->id)
                ->where('captured_at', '>=', Rfc3339::toDatabase($context->rangeStart))
                ->where('captured_at', '<', Rfc3339::toDatabase($context->rangeEnd))
                ->min('captured_at');
            $rawFrom = $oldestRaw ? CarbonImmutable::parse($oldestRaw, 'UTC')->startOfMinute() : $context->rangeEnd;

            if ($rawFrom->greaterThan($context->rangeStart)) {
                DB::table('measurement_rollups')
                    ->where('stream_id', $stream->id)
                    ->where('resolution_seconds', 60)
                    ->where('bucket_start', '>=', $context->rangeStart->format('Y-m-d H:i:s'))
                    ->where('bucket_start', '<', $rawFrom->format('Y-m-d H:i:s'))
                    ->orderBy('bucket_start')
                    ->chunk(2000, function ($rows) use ($handle, $stream, $context): void {
                        foreach ($rows as $row) {
                            $start = CarbonImmutable::parse($row->bucket_start, 'UTC');
                            $leq = fn (?string $energy, int $ms): ?float => $energy === null ? null : round((float) Decibels::leq((float) $energy, $ms), 2);
                            fwrite($handle, CsvEscaper::line([
                                'PT1M', Rfc3339::format($start), Rfc3339::format($start->addMinute()), LocalTime::display($start, $context->timezone), null,
                                $stream->device?->name, $stream->channel, null, null, $stream->deployment?->uuid, $stream->profile?->uuid, $stream->calibration?->uuid,
                                $stream->calibration_state->value, implode(' ', array_map(fn (?int $revision): string => $revision === null ? self::LOCAL_DEFAULTS : (string) $revision, json_decode($row->configuration_revisions ?? '[]', true))),
                                $leq($row->laeq_energy_sum, (int) $row->laeq_valid_ms), $row->lafmax_max, $leq($row->lceq_energy_sum, (int) $row->lceq_valid_ms),
                                $row->lcpeak_max, $leq($row->lf_energy_sum, (int) $row->lf_valid_ms), $leq($row->dbfs_energy_sum, (int) $row->dbfs_valid_ms),
                                $row->laeq_valid_ms, $row->laeq_excluded_ms, $row->quality_counts, null, null,
                            ]));
                        }
                    });
            }

            DB::table('measurements')
                ->where('stream_id', $stream->id)
                ->where('captured_at', '>=', Rfc3339::toDatabase($context->rangeStart))
                ->where('captured_at', '<', Rfc3339::toDatabase($context->rangeEnd))
                ->orderBy('id')
                ->chunkById(5000, function ($rows) use ($handle, $stream, $context): void {
                    foreach ($rows as $row) {
                        $start = CarbonImmutable::parse($row->captured_at, 'UTC');
                        fwrite($handle, CsvEscaper::line([
                            'PT1S', Rfc3339::format($start), Rfc3339::format($start->addMilliseconds((int) $row->duration_ms)), LocalTime::display($start, $context->timezone), null,
                            $stream->device?->name, $row->channel, $row->boot_id, $row->sequence, $stream->deployment?->uuid, $stream->profile?->uuid, $stream->calibration?->uuid,
                            $stream->calibration_state->value, $row->configuration_revision ?? self::LOCAL_DEFAULTS, $row->laeq_db, $row->lafmax_db, $row->lceq_db, $row->lcpeak_db,
                            $row->low_frequency_leq_db, $row->rms_dbfs, $row->duration_ms, null, implode(' ', QualityFlag::valuesFromMask((int) $row->quality_flags)),
                            $row->null_reasons, bin2hex($row->row_hash),
                        ]));
                    }
                });
        }

        fclose($handle);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function rollupRowsFor(array $item): array
    {
        $event = $item['model'];
        $start = $event->started_at->subSeconds((int) config('noise.events.snapshot_pre_seconds'))->startOfMinute();
        $end = ($event->ended_at ?? $event->started_at)->addSeconds((int) config('noise.events.snapshot_post_seconds'));

        return DB::table('measurement_rollups')
            ->where('stream_id', $event->stream_id)
            ->where('resolution_seconds', 60)
            ->where('bucket_start', '>=', $start->format('Y-m-d H:i:s'))
            ->where('bucket_start', '<', $end->format('Y-m-d H:i:s'))
            ->orderBy('bucket_start')
            ->get()
            ->map(fn ($row): array => [
                'bucket_start' => Rfc3339::format(CarbonImmutable::parse($row->bucket_start, 'UTC')),
                'expected_ms' => (int) $row->expected_ms,
                'laeq_db' => Decibels::leq($row->laeq_energy_sum === null ? null : (float) $row->laeq_energy_sum, (int) $row->laeq_valid_ms),
                'laeq_valid_ms' => (int) $row->laeq_valid_ms,
                'lafmax_max_db' => $row->lafmax_max === null ? null : (float) $row->lafmax_max,
                'quality_counts' => json_decode($row->quality_counts ?? '{}', true),
                'policy_version' => $row->policy_version,
            ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function provenanceDocuments(ExportContext $context): array
    {
        $tz = $context->timezone;

        return [
            'measurement_profiles' => $context->streams->pluck('profile')->filter()->unique('id')->map(fn ($profile): array => [
                'id' => $profile->uuid, 'device_id' => $profile->device?->uuid, 'channel' => $profile->channel, 'revision' => $profile->revision,
                'microphone_model' => $profile->microphone_model, 'microphone_serial' => $profile->microphone_serial, 'audio_interface' => $profile->audio_interface,
                'sample_rate_hz' => $profile->sample_rate_hz, 'gain_db' => $profile->gain_db, 'gain_description' => $profile->gain_description,
                'weighting_implementation_version' => $profile->weighting_implementation_version, 'filter_implementation_version' => $profile->filter_implementation_version,
                'calibration_state' => $profile->calibration_state->value, 'calibration_application_method' => $profile->calibration_application_method,
                'supported_metrics' => $profile->supported_metrics, 'low_frequency_band_hz' => [$profile->low_frequency_lower_hz, $profile->low_frequency_upper_hz],
                'band_definitions' => $profile->band_definitions, 'agent_processing_version' => $profile->agent_processing_version,
                'content_sha256' => $profile->content_hash, 'created_at' => Rfc3339::format($profile->created_at),
            ])->values()->all(),
            'placements' => $context->streams->pluck('deployment')->filter()->unique('id')->map(fn ($deployment): array => [
                'id' => $deployment->uuid, 'revision' => $deployment->revision, 'room' => $deployment->room, 'location_type' => $deployment->location_type->value,
                'placement_description' => $deployment->placement_description, 'mounting_notes' => $deployment->mounting_notes, 'height_m' => $deployment->height_m,
                'orientation' => $deployment->orientation, 'effective_at' => Rfc3339::format($deployment->effective_at), 'effective_at_local' => LocalTime::display($deployment->effective_at, $tz),
                'content_sha256' => $deployment->content_hash,
                'attachments' => $deployment->attachments->map(fn ($attachment): array => ['id' => $attachment->uuid, 'purpose' => $attachment->purpose, 'filename' => $attachment->original_filename, 'bytes' => $attachment->byte_size, 'sha256' => $attachment->sha256])->all(),
            ])->values()->all(),
            'calibrations' => $context->streams->pluck('calibration')->filter()->unique('id')->map(fn ($calibration): array => [
                'id' => $calibration->uuid, 'channel' => $calibration->channel, 'revision' => $calibration->revision, 'calibration_state' => $calibration->calibration_state->value,
                'meaning' => 'A documented measurement chain, not a certified instrument. No accuracy or uncertainty value is claimed.',
                'reference_method' => $calibration->reference_method, 'reference_device' => $calibration->reference_device, 'reference_level_db' => $calibration->reference_level_db,
                'reference_frequency_hz' => $calibration->reference_frequency_hz, 'sensitivity_mv_per_pa' => $calibration->sensitivity_mv_per_pa,
                'sensitivity_dbfs_at_94db' => $calibration->sensitivity_dbfs_at_94db, 'gain_configuration' => $calibration->gain_configuration,
                'application_method' => $calibration->application_method, 'correction_metadata' => $calibration->correction_metadata,
                'performed_at' => Rfc3339::format($calibration->performed_at), 'performed_by' => $calibration->performed_by, 'notes' => $calibration->notes,
                'content_sha256' => $calibration->content_hash,
                'field_checks' => $calibration->fieldChecks->map(fn ($check): array => ['id' => $check->uuid, 'checked_at' => Rfc3339::format($check->checked_at), 'reference_source' => $check->reference_source, 'expected_level_db' => $check->expected_level_db, 'measured_level_db' => $check->measured_level_db, 'passed' => $check->passed, 'notes' => $check->notes])->all(),
                'attachments' => $calibration->attachments->map(fn ($attachment): array => ['id' => $attachment->uuid, 'purpose' => $attachment->purpose, 'filename' => $attachment->original_filename, 'bytes' => $attachment->byte_size, 'sha256' => $attachment->sha256])->all(),
            ])->values()->all(),
            'configurations' => $context->configurations->map(fn ($configuration): array => [
                'id' => $configuration->uuid, 'revision' => $configuration->revision, 'content_sha256' => $configuration->content_hash,
                'issued_at' => Rfc3339::format($configuration->issued_at), 'rollback_of_revision' => $configuration->rollback_of_revision, 'document' => $configuration->document,
                'acknowledgments' => $configuration->acknowledgments->map(fn ($ack): array => ['status' => $ack->status->value, 'reason' => $ack->reason, 'reported_applied_at' => Rfc3339::format($ack->reported_applied_at), 'received_at' => Rfc3339::format($ack->received_at)])->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @return list<Attachment>
     */
    private function attachments(ExportContext $context): array
    {
        $attachments = [];

        foreach ($context->streams as $stream) {
            foreach ([$stream->deployment?->attachments, $stream->calibration?->attachments] as $set) {
                foreach ($set ?? [] as $attachment) {
                    $attachments[$attachment->id] = $attachment;
                }
            }
        }

        ksort($attachments);

        return array_values($attachments);
    }

    private function readme(EvidenceExport $export, ExportContext $context): string
    {
        return implode("\n\n", [
            'Noise Monitor evidence bundle '.$export->uuid,
            'Property: '.$context->property->name.' (timezone '.$context->timezone.')',
            'Selection frozen at '.Rfc3339::format($context->referenceTime).'. Later annotations, revisions, or recordings are not included; request a new export to capture them.',
            'manifest.json lists every file with its byte length, SHA-256, and source identifiers, plus explanations of missing data.',
            self::HASH_STATEMENT,
            self::DISCLAIMER,
        ])."\n";
    }

    private function sanitizeNote(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        return Str::limit($text, 4000);
    }

    private function db(mixed $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 1);
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n";
    }

    private function safeName(string $name): string
    {
        return trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'file', '._') ?: 'file';
    }

    private function fileName(EvidenceExport $export, ExportContext $context, string $extension): string
    {
        $prefix = match ($export->kind) {
            ExportKind::PdfSummary => 'noise-summary',
            ExportKind::CsvMeasurements => 'noise-measurements',
            ExportKind::EvidenceBundle => 'noise-evidence',
        };

        return sprintf('%s-%s-%s-%s.%s', $prefix, Str::slug($context->property->name) ?: 'property', $context->referenceTime->setTimezone($context->timezone)->format('Ymd'), Str::substr($export->uuid, 0, 8), $extension);
    }
}
