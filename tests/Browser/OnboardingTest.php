<?php

use App\Enums\MembershipRole;
use App\Models\Account;
use App\Models\Device;
use App\Models\Property;
use App\Models\User;

it('lets an owner sign in, create a property and device, and obtain a credential once', function (): void {
    $account = Account::factory()->create(['name' => 'Browser household']);
    $owner = User::factory()->create(['email' => 'owner@example.test', 'password' => 'correct-horse-battery']);
    $account->users()->attach($owner, ['role' => MembershipRole::Owner->value]);

    $page = visit('/app/login')->wait(1)
        ->fill('[id="form.email"]', 'owner@example.test')
        ->fill('[id="form.password"]', 'correct-horse-battery')
        ->click('button[type="submit"]')
        ->waitForText('Set up monitoring')
        ->assertSee('Create a property')
        ->assertNoJavaScriptErrors();

    $page->navigate("/app/{$account->uuid}/properties/create")->wait(1)
        ->fill('[id="form.name"]', 'Home')
        ->click('button[wire\:target="create"]')
        ->wait(2);

    $property = Property::query()->where('account_id', $account->id)->firstOrFail();
    expect($property->name)->toBe('Home')->and($property->timezone)->toBe('America/Chicago');

    $page->navigate("/app/{$account->uuid}/devices/create")->wait(1)
        ->fill('[id="form.name"]', 'Front window Pi')
        ->select('[id="form.property_id"]', (string) $property->id)
        ->click('button[wire\:target="create"]')
        ->waitForText('Issue credential')
        ->click('Issue credential')
        ->wait(1)
        ->click('.fi-modal-window button.fi-color-primary')
        ->waitForText('copy it now')
        ->assertSee('nmd_')
        ->assertNoJavaScriptErrors();

    $device = Device::query()->where('name', 'Front window Pi')->firstOrFail();
    expect($device->property_id)->toBe($property->id)
        ->and($device->credentials()->count())->toBe(1)
        ->and($device->credentials()->first()->token_hash)->toHaveLength(64);
});
