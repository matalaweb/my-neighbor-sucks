<?php

use App\Enums\ExportKind;
use App\Enums\ExportStatus;
use App\Enums\MembershipRole;
use App\Jobs\BuildExportJob;
use App\Models\AuditLog;
use App\Models\EventAnnotation;
use App\Models\User;
use App\Services\Exports\BuildEvidenceExport;
use App\Services\Exports\ExportContext;
use App\Services\Exports\ExportDownloads;
use App\Services\Exports\RequestEvidenceExport;
use App\Services\Storage\EvidenceStorage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\DeviceFixture;
use Tests\Support\EvidenceScenario;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-08T12:20:00Z');
    $this->fixture = DeviceFixture::create();
    $start = CarbonImmutable::parse('2026-10-08T12:14:00Z');
    EvidenceScenario::measurements($this->fixture, $start, 180, 1, fn (array $record, int $i) => $i >= 60 && $i < 80
        ? [...$record, 'laeq_db' => 72.0 + ($i % 5), 'lafmax_db' => 84.0 + ($i % 3)]
        : $record);
    $this->event = EvidenceScenario::event($this->fixture, CarbonImmutable::parse('2026-10-08T12:15:00Z'), 20, "=HYPERLINK(\"http://evil\")\n<script>alert('x')</script> loud exhaust");
    $this->recording = EvidenceScenario::verifiedRecording($this->event);
    $this->storage = app(EvidenceStorage::class);
});

function zipEntries(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    $zip->open($path);
    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $entries[$name] = $zip->getFromName($name);
    }

    $zip->close();
    unlink($path);

    return $entries;
}

it('builds a traceable evidence bundle from a frozen selection', function (): void {
    $export = app(RequestEvidenceExport::class)->handle($this->fixture->owner, $this->fixture->property, ExportKind::EvidenceBundle, [
        'type' => 'events', 'event_uuids' => [$this->event->uuid],
    ]);

    // Annotations added after the request are not part of this export.
    EventAnnotation::query()->create([
        'account_id' => $this->event->account_id, 'noise_event_id' => $this->event->id, 'author_id' => $this->fixture->owner->id,
        'kind' => EventAnnotation::KIND_NOTE, 'notes' => 'LATE NOTE', 'created_at' => CarbonImmutable::now(),
    ]);

    $export = app(BuildEvidenceExport::class)->build($export);

    expect($export->status)->toBe(ExportStatus::Ready)
        ->and($export->expires_at->toIso8601ZuluString())->toBe('2026-10-15T12:20:00Z');

    $bytes = $this->storage->disk()->get($export->object_key);
    expect(hash('sha256', $bytes))->toBe($export->export_sha256);

    $entries = zipEntries($bytes);
    $manifest = json_decode($entries['manifest.json'], true);
    $base = 'events/'.$this->event->uuid;

    expect($entries)->toHaveKeys(['summary.pdf', 'events.csv', 'README.txt', $base.'/revisions.json', $base.'/annotations.json', $base.'/measurements-snapshot.json', 'provenance/measurement_profiles.json', 'provenance/calibrations.json', 'provenance/configurations.json', 'provenance/placements.json'])
        ->and($manifest['hash_statement'])->toContain('do not prove capture authenticity')
        ->and($manifest['event_ids'])->toBe([$this->event->uuid])
        ->and($entries[$base.'/annotations.json'])->not->toContain('LATE NOTE');

    foreach ($manifest['files'] as $file) {
        expect(hash('sha256', $entries[$file['path']]))->toBe($file['sha256'])
            ->and(strlen($entries[$file['path']]))->toBe($file['bytes']);
    }

    $audio = collect($manifest['files'])->firstWhere('source.type', 'verified_original_recording');
    expect($audio['sha256'])->toBe($this->recording->verified_sha256)
        ->and($entries[$audio['path']])->toBe($this->storage->disk()->get($this->recording->final_key))
        ->and(json_encode($manifest))->not->toContain('X-Amz-Signature');
});

it('is idempotent when the build job runs again and never modifies source recordings', function (): void {
    $sourceBefore = $this->storage->disk()->get($this->recording->final_key);
    $export = app(RequestEvidenceExport::class)->handle($this->fixture->owner, $this->fixture->property, ExportKind::EvidenceBundle, [
        'type' => 'events', 'event_uuids' => [$this->event->uuid],
    ]);

    $first = app(BuildEvidenceExport::class)->build($export);
    $objectBefore = $this->storage->disk()->get($first->object_key);
    $second = app(BuildEvidenceExport::class)->build($first);
    (new BuildExportJob($export->id))->handle(app(BuildEvidenceExport::class));

    expect($second->export_sha256)->toBe($first->export_sha256)
        ->and($second->finished_at->eq($first->finished_at))->toBeTrue()
        ->and($this->storage->disk()->get($first->object_key))->toBe($objectBefore)
        ->and($this->storage->disk()->get($this->recording->final_key))->toBe($sourceBefore)
        ->and($this->recording->fresh()->verified_sha256)->toBe(hash('sha256', $sourceBefore))
        ->and(AuditLog::query()->where('action', 'export.ready')->count())->toBe(1);
});

it('escapes spreadsheet formulas in CSV output and hostile notes in the PDF HTML', function (): void {
    $export = app(RequestEvidenceExport::class)->handle($this->fixture->owner, $this->fixture->property, ExportKind::EvidenceBundle, [
        'type' => 'events', 'event_uuids' => [$this->event->uuid],
    ]);
    $this->fixture->device->forceFill(['name' => '=cmd|calc!A1'])->save();

    $builder = app(BuildEvidenceExport::class);
    $context = ExportContext::load($export->fresh());
    $html = view('exports.summary', $builder->reportData($export, $context, CarbonImmutable::now()))->render();

    expect($html)->not->toContain("<script>alert('x')</script>")
        ->and($html)->toContain('&lt;script&gt;');

    $export = $builder->build($export);
    $csv = zipEntries($this->storage->disk()->get($export->object_key))['events.csv'];

    expect($csv)->toContain("'=cmd|calc!A1")
        ->and($csv)->not->toMatch('/(^|,)=cmd/m');
});

it('exports one-second measurements for a date range as CSV with an explicit resolution', function (): void {
    $export = app(RequestEvidenceExport::class)->handle($this->fixture->owner, $this->fixture->property, ExportKind::CsvMeasurements, [
        'type' => 'range', 'from_date' => '2026-10-08', 'to_date' => '2026-10-08',
    ]);

    $export = app(BuildEvidenceExport::class)->build($export);
    $lines = array_filter(explode("\n", $this->storage->disk()->get($export->object_key)));

    expect($export->status)->toBe(ExportStatus::Ready)
        ->and(count($lines))->toBe(181)
        ->and($lines[1])->toStartWith('PT1S,2026-10-08T12:14:00.000Z');
});

it('renders a PDF summary', function (): void {
    $export = app(RequestEvidenceExport::class)->handle($this->fixture->owner, $this->fixture->property, ExportKind::PdfSummary, [
        'type' => 'range', 'from_date' => '2026-10-08', 'to_date' => '2026-10-08',
    ]);

    $export = app(BuildEvidenceExport::class)->build($export);
    $pdf = $this->storage->disk()->get($export->object_key);

    expect($pdf)->toStartWith('%PDF')->and($export->file_name)->toEndWith('.pdf');

    if (getenv('EXPORT_SAMPLE_DIR')) {
        file_put_contents(getenv('EXPORT_SAMPLE_DIR').'/range-summary.pdf', $pdf);
        $events = app(BuildEvidenceExport::class)->build(app(RequestEvidenceExport::class)->handle($this->fixture->owner, $this->fixture->property, ExportKind::PdfSummary, ['type' => 'events', 'event_uuids' => [$this->event->uuid]]));
        file_put_contents(getenv('EXPORT_SAMPLE_DIR').'/event-summary.pdf', $this->storage->disk()->get($events->object_key));
    }
});

it('enforces export limits unless an owner overrides them', function (): void {
    $request = app(RequestEvidenceExport::class);

    expect(fn () => $request->handle($this->fixture->owner, $this->fixture->property, ExportKind::PdfSummary, ['type' => 'range', 'from_date' => '2026-08-01', 'to_date' => '2026-10-08']))
        ->toThrow(ValidationException::class);

    $reviewer = User::factory()->create();
    $this->fixture->account->users()->attach($reviewer, ['role' => MembershipRole::Reviewer->value]);

    expect(fn () => $request->handle($reviewer, $this->fixture->property, ExportKind::PdfSummary, ['type' => 'range', 'from_date' => '2026-08-01', 'to_date' => '2026-10-08'], ownerOverride: true))
        ->toThrow(AuthorizationException::class);

    $export = $request->handle($this->fixture->owner, $this->fixture->property, ExportKind::PdfSummary, ['type' => 'range', 'from_date' => '2026-08-01', 'to_date' => '2026-10-08'], ownerOverride: true);
    expect($export->limits_overridden)->toBeTrue();
});

it('denies export creation and download to viewers and other accounts', function (): void {
    $viewer = User::factory()->create();
    $this->fixture->account->users()->attach($viewer, ['role' => MembershipRole::Viewer->value]);
    $other = DeviceFixture::create();

    expect(fn () => app(RequestEvidenceExport::class)->handle($viewer, $this->fixture->property, ExportKind::PdfSummary, ['type' => 'events', 'event_uuids' => [$this->event->uuid]]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(RequestEvidenceExport::class)->handle($other->owner, $this->fixture->property, ExportKind::PdfSummary, ['type' => 'events', 'event_uuids' => [$this->event->uuid]]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(RequestEvidenceExport::class)->handle($other->owner, $other->property, ExportKind::PdfSummary, ['type' => 'events', 'event_uuids' => [$this->event->uuid]]))
        ->toThrow(ValidationException::class);

    $export = app(BuildEvidenceExport::class)->build(app(RequestEvidenceExport::class)->handle($this->fixture->owner, $this->fixture->property, ExportKind::PdfSummary, ['type' => 'events', 'event_uuids' => [$this->event->uuid]]));

    expect(fn () => app(ExportDownloads::class)->issueUrl($viewer, $export))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ExportDownloads::class)->issueUrl($other->owner, $export))->toThrow(AuthorizationException::class);

    $url = app(ExportDownloads::class)->issueUrl($this->fixture->owner, $export);
    expect($url)->toContain('X-Amz-Signature')
        ->and(AuditLog::query()->where('action', 'export.downloaded')->count())->toBe(1);
});

it('fails the queued build when the requester lost export permission', function (): void {
    $export = app(RequestEvidenceExport::class)->handle($this->fixture->owner, $this->fixture->property, ExportKind::PdfSummary, ['type' => 'events', 'event_uuids' => [$this->event->uuid]]);
    $this->fixture->account->users()->updateExistingPivot($this->fixture->owner->id, ['role' => MembershipRole::Viewer->value]);

    $export = app(BuildEvidenceExport::class)->build($export);

    expect($export->status)->toBe(ExportStatus::Failed)->and($export->object_key)->toBeNull();
});
