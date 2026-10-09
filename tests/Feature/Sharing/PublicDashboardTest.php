<?php

use App\Enums\DeviceStatus;
use App\Enums\MembershipRole;
use App\Filament\Resources\Devices\Pages\ViewDevice;
use App\Livewire\PublicDeviceDashboard;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\NoiseEvent;
use App\Models\User;
use App\Services\Devices\DeviceSharingService;
use App\Services\Events\AnnotateEvent;
use App\Services\Events\SnapshotEventMeasurements;
use App\Services\Measurements\RebuildRollups;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\DeviceFixture;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-08T18:00:00Z');
    $this->fixture = DeviceFixture::create();
    $this->fixture->property->update(['address' => '742 Evergreen Terrace', 'notes' => 'Gate code 4321']);
    $this->fixture->device->update(['name' => 'Bedroom window Pi', 'public_title' => 'Maple Street driveway']);
    $start = CarbonImmutable::parse('2026-10-08T17:30:00Z');

    $this->devicePost($this->fixture, 'measurements/batches', $this->fixture->batch($this->fixture->records(120, $start)))->assertCreated();
    $eventId = (string) Str::uuid();
    $this->devicePost($this->fixture, 'events', $this->fixture->event($eventId, 1, $start->addSeconds(40), $start->addSeconds(55)))->assertCreated();
    app(RebuildRollups::class)->processDirty();
    app(SnapshotEventMeasurements::class)->processDirty();
    $this->event = NoiseEvent::query()->where('uuid', $eventId)->firstOrFail();
    $this->flushHeaders();
    $this->resetGuard();

    $this->sharing = app(DeviceSharingService::class);
    $this->token = $this->sharing->share($this->fixture->device, $this->fixture->owner);
});

it('shows a shared device to anyone with the link', function (): void {
    $this->get("/share/{$this->token}")
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee('Maple Street driveway')
        ->assertSee('Current level (LAeq)')
        ->assertSee('loudest moment')
        ->assertSee('Recent events')
        ->assertSee('Unreviewed')
        ->assertSee('Calibrated (documented chain)');
});

it('returns 404 for links that are not active', function (string $case): void {
    $token = match ($case) {
        'unknown' => DeviceSharingService::TOKEN_PREFIX.Str::random(48),
        'wrong prefix' => 'nmd_'.substr($this->token, 4),
        'revoked' => tap($this->token, fn () => $this->sharing->stop($this->fixture->device, $this->fixture->owner)),
        'regenerated' => tap($this->token, fn () => $this->sharing->share($this->fixture->device, $this->fixture->owner)),
        'archived device' => tap($this->token, fn () => $this->fixture->device->forceFill(['status' => DeviceStatus::Archived])->save()),
    };

    $this->get("/share/{$token}")->assertNotFound();
})->with(['unknown', 'wrong prefix', 'revoked', 'regenerated', 'archived device']);

it('serves the regenerated link', function (): void {
    $newToken = $this->sharing->share($this->fixture->device, $this->fixture->owner);

    expect($newToken)->not->toBe($this->token);
    $this->get("/share/{$newToken}")->assertOk()->assertSee('Maple Street driveway');
});

it('stores only a hash and an encrypted copy of the token', function (): void {
    $raw = Device::query()->toBase()->where('id', $this->fixture->device->id)->first(['share_token', 'share_token_hash']);

    expect($raw->share_token_hash)->toBe(hash('sha256', $this->token))
        ->and($raw->share_token)->not->toContain($this->token)
        ->and($this->fixture->device->fresh()->publicShareUrl())->toBe(route('share.show', $this->token));
});

it('never exposes private details on the public page', function (): void {
    app(AnnotateEvent::class)->review($this->event, $this->fixture->owner, [
        'review_status' => 'confirmed_disturbance',
        'source_label' => 'engine_like',
        'source_certainty' => 'observed',
        'notes' => 'Neighbour revving again',
    ]);

    $this->get("/share/{$this->token}")
        ->assertOk()
        ->assertSee('Confirmed disturbance')
        ->assertDontSee('Bedroom window Pi')
        ->assertDontSee($this->fixture->property->name)
        ->assertDontSee('742 Evergreen Terrace')
        ->assertDontSee('Gate code 4321')
        ->assertDontSee('Front bedroom')
        ->assertDontSee('Window sill facing driveway')
        ->assertDontSee('Engine-like noise')
        ->assertDontSee('Neighbour revving again')
        ->assertDontSee($this->fixture->device->uuid)
        ->assertDontSee($this->event->uuid)
        ->assertDontSee('placement r')
        ->assertDontSee('audio');
});

it('escapes the public title', function (): void {
    $this->fixture->device->update(['public_title' => '<script>alert("x")</script>']);

    $this->get("/share/{$this->token}")
        ->assertOk()
        ->assertSee('&lt;script&gt;', escape: false)
        ->assertDontSee('<script>alert("x")</script>', escape: false);
});

it('only shows the shared device', function (): void {
    $other = Device::factory()->for($this->fixture->property)->create(['account_id' => $this->fixture->account->id, 'public_title' => 'Backyard sensor']);

    $this->get("/share/{$this->token}")->assertOk()->assertDontSee('Backyard sensor');
    expect($other->isPubliclyShared())->toBeFalse();
});

it('switches chart ranges and ignores unknown ranges', function (): void {
    Livewire::test(PublicDeviceDashboard::class, ['token' => $this->token])
        ->assertSet('range', '24h')
        ->call('setRange', '7d')
        ->assertSet('range', '7d')
        ->assertSee('aria-pressed="true"', escape: false)
        ->call('setRange', '1y')
        ->assertSet('range', '24h');
});

it('stops serving an open page once the link is revoked', function (): void {
    $component = Livewire::test(PublicDeviceDashboard::class, ['token' => $this->token])->assertOk();

    $this->sharing->stop($this->fixture->device, $this->fixture->owner);

    $component->call('$refresh')->assertNotFound();
});

it('rate limits the public link', function (): void {
    config(['noise.sharing.requests_per_minute' => 2]);

    $this->get("/share/{$this->token}")->assertOk();
    $this->get("/share/{$this->token}")->assertOk();
    $this->get("/share/{$this->token}")->assertTooManyRequests();
});

it('audits sharing changes', function (): void {
    $this->sharing->share($this->fixture->device, $this->fixture->owner);
    $this->sharing->stop($this->fixture->device, $this->fixture->owner);

    expect(AuditLog::query()->where('action', 'like', 'device.sharing.%')->orderBy('id')->pluck('action')->all())
        ->toBe(['device.sharing.enabled', 'device.sharing.regenerated', 'device.sharing.disabled']);
});

it('refuses to share an archived device', function (): void {
    $this->fixture->device->forceFill(['status' => DeviceStatus::Archived])->save();

    $this->sharing->share($this->fixture->device, $this->fixture->owner);
})->throws(RuntimeException::class, 'Archived devices cannot be shared.');

it('lets an owner share from the device page', function (): void {
    $this->sharing->stop($this->fixture->device, $this->fixture->owner);
    $this->actingAs($this->fixture->owner);
    Filament::setTenant($this->fixture->account);

    Livewire::test(ViewDevice::class, ['record' => $this->fixture->device->getRouteKey()])
        ->assertActionVisible('share')
        ->assertActionHidden('stopSharing')
        ->callAction('share', ['public_title' => 'Elm Court'])
        ->assertHasNoActionErrors();

    $device = $this->fixture->device->fresh();
    expect($device->isPubliclyShared())->toBeTrue()
        ->and($device->public_title)->toBe('Elm Court');
});

it('hides sharing controls from members who are not owners', function (MembershipRole $role): void {
    $member = User::factory()->create();
    $this->fixture->account->users()->attach($member, ['role' => $role->value]);
    $this->actingAs($member);
    Filament::setTenant($this->fixture->account);

    Livewire::test(ViewDevice::class, ['record' => $this->fixture->device->getRouteKey()])
        ->assertActionHidden('share')
        ->assertActionHidden('shareLink')
        ->assertActionHidden('regenerateShareLink')
        ->assertActionHidden('stopSharing');
})->with([MembershipRole::Reviewer, MembershipRole::Viewer]);
