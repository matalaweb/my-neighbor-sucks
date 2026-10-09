<?php

use App\Services\Devices\DeviceSharingService;
use Carbon\CarbonImmutable;
use Tests\Support\DeviceFixture;
use Tests\Support\EvidenceScenario;

beforeEach(function (): void {
    $this->fixture = DeviceFixture::create();
    $this->fixture->device->update(['public_title' => 'Maple Street driveway']);
    $start = CarbonImmutable::now()->subMinutes(10)->startOfMinute();
    EvidenceScenario::measurements($this->fixture, $start, 90);
    EvidenceScenario::event($this->fixture, $start->addSeconds(30), 15);
    $this->token = app(DeviceSharingService::class)->share($this->fixture->device, $this->fixture->owner);
});

it('renders the public dashboard chart and switches ranges without signing in', function (): void {
    $page = visit("/share/{$this->token}")
        ->waitForText('Maple Street driveway')
        ->assertSee('Sound level over time')
        ->assertPresent('canvas[role="img"]')
        ->click('7d')
        ->wait(1)
        ->assertPresent('button[aria-pressed="true"]')
        ->assertPresent('canvas[aria-label="LAeq over the last 7 days"]');

    expect($page->script("document.querySelector('button[aria-pressed=\"true\"]').textContent.trim()"))->toBe('7d');

    $page->assertNoJavaScriptErrors();
});
