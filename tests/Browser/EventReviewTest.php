<?php

use App\Enums\ExportStatus;
use App\Enums\ReviewStatus;
use App\Jobs\BuildExportJob;
use App\Models\AuditLog;
use App\Models\EventAnnotation;
use App\Models\EvidenceExport;
use App\Services\Exports\BuildEvidenceExport;
use Carbon\CarbonImmutable;
use Tests\Support\DeviceFixture;
use Tests\Support\EvidenceScenario;

beforeEach(function (): void {
    // Inside the test container the browser reaches object storage at the internal endpoint.
    config(['noise.storage.browser_endpoint' => null]);

    $this->fixture = DeviceFixture::create();
    $start = CarbonImmutable::now()->subMinutes(10)->startOfMinute();
    EvidenceScenario::measurements($this->fixture, $start, 90);
    $this->event = EvidenceScenario::event($this->fixture, $start->addSeconds(30), 15);
    $this->recording = EvidenceScenario::verifiedRecording($this->event, EvidenceScenario::wav(3));
    $this->url = "/app/{$this->fixture->account->uuid}/events/{$this->event->uuid}";
});

it('plays a verified recording through a short-lived authorized link', function (): void {
    $this->actingAs($this->fixture->owner);

    $page = visit($this->url)
        ->waitForText('Readings around the event')
        ->assertSee('Verified SHA-256')
        ->click('Load player')
        ->wait(2)
        ->assertPresent('audio[controls]');

    $state = $page->script("(() => { const a = document.querySelector('audio'); return { ready: a.readyState, duration: a.duration, signed: a.src.includes('X-Amz-Signature') }; })()");

    expect($state['signed'])->toBeTrue()
        ->and($state['ready'])->toBeGreaterThanOrEqual(1)
        ->and($state['duration'])->toEqualWithDelta(3.0, 0.05);

    $page->assertNoJavaScriptErrors();
    expect(AuditLog::query()->where('action', 'recording.playback_url_issued')->count())->toBe(1);
});

it('lets a reviewer label an event with an append-only annotation', function (): void {
    $this->actingAs($this->fixture->owner);

    visit($this->url)
        ->waitForText('Not reviewed yet.')
        ->click('Review')
        ->wait(1)
        ->click('Confirmed disturbance')
        ->select('[id$=".source_label"]', 'engine_like')
        ->click('Suspected')
        ->fill('[id$=".notes"]', 'Loud revving from the driveway; =HYPERLINK("x") <script>alert(1)</script>')
        ->click('.fi-modal-window button[type="submit"]')
        ->waitForText('Review saved.')
        ->assertSee('Suspected')
        ->assertNoJavaScriptErrors();

    $annotation = EventAnnotation::query()->where('noise_event_id', $this->event->id)->latest('id')->firstOrFail();

    expect($annotation->review_status)->toBe(ReviewStatus::ConfirmedDisturbance)
        ->and($annotation->notes)->not->toContain('<script>')
        ->and($this->event->fresh()->review_status)->toBe(ReviewStatus::ConfirmedDisturbance)
        ->and($this->event->revisions()->first()->payload['summary'])->toBe($this->event->revisions()->first()->getOriginal('payload')['summary'] ?? $this->event->revisions()->first()->payload['summary']);
});

it('queues an evidence bundle from the event and downloads it from Reports', function (): void {
    $this->actingAs($this->fixture->owner);

    visit($this->url)
        ->waitForText('Readings around the event')
        ->click('More')
        ->click('Export this event')
        ->wait(1)
        ->click('.fi-modal-window button[type="submit"]')
        ->waitForText('Export queued.');

    $export = EvidenceExport::query()->latest('id')->firstOrFail();
    (new BuildExportJob($export->id))->handle(app(BuildEvidenceExport::class));
    expect($export->fresh()->status)->toBe(ExportStatus::Ready);

    visit("/app/{$this->fixture->account->uuid}/reports")
        ->waitForText('Ready')
        ->click('Download')
        ->wait(2);

    expect(AuditLog::query()->where('action', 'export.downloaded')->count())->toBe(1);
});
