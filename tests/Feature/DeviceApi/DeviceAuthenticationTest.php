<?php

use App\Models\DeviceCredential;
use App\Services\Devices\DeviceCredentialService;
use Carbon\CarbonImmutable;
use Tests\Support\DeviceFixture;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-08T12:20:00Z');
    $this->fixture = DeviceFixture::create();
    $this->credentials = app(DeviceCredentialService::class);
});

it('stops a revoked credential immediately', function (): void {
    $this->deviceGet($this->fixture, 'configuration')->assertOk();

    $this->credentials->revoke(DeviceCredential::query()->sole(), $this->fixture->owner);

    $this->deviceGet($this->fixture, 'configuration')->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
});

it('rejects expired credentials and credentials of archived devices', function (): void {
    DeviceCredential::query()->update(['expires_at' => CarbonImmutable::now()->subMinute()]);
    $this->deviceGet($this->fixture, 'configuration')->assertStatus(401);

    DeviceCredential::query()->update(['expires_at' => null]);
    $this->deviceGet($this->fixture, 'configuration')->assertOk();

    $this->fixture->device->forceFill(['status' => 'archived'])->save();
    $this->deviceGet($this->fixture, 'configuration')->assertStatus(401);
});

it('lets both credentials work during a rotation window then retires the old one', function (): void {
    $oldToken = $this->fixture->token;
    $new = $this->credentials->rotate($this->fixture->device, $this->fixture->owner, 24);

    $this->fixture->token = $new['token'];
    $this->deviceGet($this->fixture, 'configuration')->assertOk();
    $this->fixture->token = $oldToken;
    $this->deviceGet($this->fixture, 'configuration')->assertOk();

    CarbonImmutable::setTestNow('2026-10-09T12:21:00Z');
    $this->deviceGet($this->fixture, 'configuration')->assertStatus(401);
    $this->fixture->token = $new['token'];
    $this->deviceGet($this->fixture, 'configuration')->assertOk();

    expect(DeviceCredential::query()->where('device_id', $this->fixture->device->id)->count())->toBe(2)
        ->and(DeviceCredential::query()->pluck('token_hash')->contains(hash('sha256', $new['token'])))->toBeTrue()
        ->and(DeviceCredential::query()->pluck('token_hash')->contains($new['token']))->toBeFalse();
});

it('forbids abilities the credential does not grant', function (): void {
    DeviceCredential::query()->update(['abilities' => json_encode([DeviceCredential::ABILITY_HEARTBEAT])]);

    $this->deviceGet($this->fixture, 'configuration')->assertStatus(403)->assertJsonPath('error.code', 'forbidden_ability');
    $this->devicePost($this->fixture, 'measurements/batches', ['schema_version' => 1])->assertStatus(403);
});

it('never lets a device credential reach human routes', function (): void {
    foreach (['/app', '/app/'.$this->fixture->account->uuid] as $uri) {
        $response = $this->withHeaders($this->fixture->headers())->get($uri);

        expect($response->status())->toBeIn([302, 401, 403, 404], $uri.' returned '.$response->status());
        $this->assertGuest('web');
    }
});

it('cannot use a human session to call the device API', function (): void {
    $this->actingAs($this->fixture->owner)->getJson('/api/v1/device/configuration')->assertStatus(401);
});
