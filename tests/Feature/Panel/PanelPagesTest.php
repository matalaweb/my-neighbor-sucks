<?php

use App\Enums\MembershipRole;
use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Resources\EvidenceExports\EvidenceExportResource;
use App\Filament\Resources\NoiseEvents\NoiseEventResource;
use App\Filament\Resources\Properties\PropertyResource;
use App\Models\Account;
use App\Models\NoiseEvent;
use App\Models\User;
use App\Services\Events\SnapshotEventMeasurements;
use App\Services\Measurements\RebuildRollups;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\DeviceFixture;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-08T18:00:00Z');
    $this->fixture = DeviceFixture::create();
    $start = CarbonImmutable::parse('2026-10-08T17:30:00Z');

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($this->fixture->records(120, $start)))->assertCreated();
    $this->eventId = (string) Str::uuid();
    $this->devicePost($this->fixture, 'events', $this->fixture->event($this->eventId, 1, $start->addSeconds(40), $start->addSeconds(55)))->assertCreated();
    app(RebuildRollups::class)->processDirty();
    app(SnapshotEventMeasurements::class)->processDirty();
    $this->event = NoiseEvent::query()->where('uuid', $this->eventId)->first();
    $this->tenant = ['tenant' => $this->fixture->account];
    $this->flushHeaders();
});

function panelUrl(string $path): string
{
    return $path;
}

it('renders every principal page for an owner', function (): void {
    $this->actingAs($this->fixture->owner);
    $tenant = $this->fixture->account->uuid;

    $this->get("/app/{$tenant}")->assertOk()->assertSee('Latest LAeq')->assertSee('dBA')->assertSee('Max LAFmax');
    $this->get(DeviceResource::getUrl('index', $this->tenant))->assertOk();
    $this->get(DeviceResource::getUrl('view', ['record' => $this->fixture->device, ...$this->tenant]))->assertOk()->assertSee('Credentials');
    $this->get(NoiseEventResource::getUrl('index', $this->tenant))->assertOk();
    $this->get(NoiseEventResource::getUrl('view', ['record' => $this->event, ...$this->tenant]))->assertOk()->assertSee('Readings around the event')->assertSee('Agent summary');
    $this->get(EvidenceExportResource::getUrl('index', $this->tenant))->assertOk();
    $this->get(PropertyResource::getUrl('index', $this->tenant))->assertOk();
    $this->get("/app/{$tenant}/account-settings")->assertOk()->assertSee('Members');
    $this->get("/app/{$tenant}/operational-status")->assertOk()->assertSee('Recent audit events');
});

it('shows an onboarding empty state for a new account', function (): void {
    $owner = User::factory()->create();
    $account = Account::factory()->create();
    $account->users()->attach($owner, ['role' => MembershipRole::Owner->value]);

    $this->actingAs($owner)->get("/app/{$account->uuid}")->assertOk()->assertSee('Set up monitoring')->assertSee('Create a property');
});

it('prevents a second account from reaching the first account data', function (): void {
    $other = DeviceFixture::create();
    $this->actingAs($other->owner);

    $this->get("/app/{$this->fixture->account->uuid}")->assertNotFound();
    $this->get(DeviceResource::getUrl('view', ['record' => $this->fixture->device, 'tenant' => $other->account]))->assertNotFound();
    $this->get(NoiseEventResource::getUrl('view', ['record' => $this->event, 'tenant' => $other->account]))->assertNotFound();
    $this->get(NoiseEventResource::getUrl('index', ['tenant' => $other->account]))->assertOk()->assertDontSee(substr($this->eventId, 0, 8));
});

it('requires authentication and rejects device credentials on human routes', function (): void {
    $this->get("/app/{$this->fixture->account->uuid}")->assertRedirect();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->fixture->token])
        ->get("/app/{$this->fixture->account->uuid}")
        ->assertRedirect();
});

it('limits viewers to reading', function (): void {
    $viewer = User::factory()->create();
    $this->fixture->account->users()->attach($viewer, ['role' => MembershipRole::Viewer->value]);
    $this->actingAs($viewer);

    $this->get(NoiseEventResource::getUrl('view', ['record' => $this->event, ...$this->tenant]))->assertOk()->assertDontSee('Download original');
    $this->get(DeviceResource::getUrl('create', $this->tenant))->assertForbidden();
    expect($viewer->can('annotate', $this->event))->toBeFalse()
        ->and($viewer->can('playRecording', $this->event))->toBeTrue()
        ->and($viewer->can('downloadOriginal', $this->event))->toBeFalse();
});
