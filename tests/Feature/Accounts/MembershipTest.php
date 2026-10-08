<?php

use App\Enums\MembershipRole;
use App\Models\Account;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\AccountInvitation;
use App\Services\Accounts\MembershipService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

it('creates the initial owner, account, and property from the command', function (): void {
    $this->artisan('noise:create-owner', [
        'email' => 'Owner@Example.test',
        '--account' => 'Household',
        '--property' => 'Home',
        '--generate-password' => true,
    ])->assertSuccessful();

    $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
    $account = Account::query()->where('name', 'Household')->firstOrFail();

    expect($owner->email_verified_at)->not->toBeNull()
        ->and($owner->roleIn($account))->toBe(MembershipRole::Owner)
        ->and($account->properties()->value('timezone'))->toBe('America/Chicago');

    $this->artisan('noise:create-owner', ['email' => 'owner@example.test', '--generate-password' => true])->assertFailed();
});

it('creates the first owner on first boot only once', function (): void {
    $this->artisan('noise:create-owner', ['email' => 'first@example.test', '--if-none' => true, '--generate-password' => true])->assertSuccessful();
    $this->artisan('noise:create-owner', ['email' => 'second@example.test', '--if-none' => true, '--generate-password' => true])
        ->expectsOutputToContain('skipping')
        ->assertSuccessful();

    expect(User::query()->pluck('email')->all())->toBe(['first@example.test']);
});

it('invites by expiring link without sending a password and accepts once', function (): void {
    Notification::fake();
    $account = Account::factory()->create();
    $owner = User::factory()->create();
    $account->users()->attach($owner, ['role' => MembershipRole::Owner->value]);

    app(MembershipService::class)->invite($account, $owner, 'reviewer@example.test', MembershipRole::Reviewer);

    $token = null;
    Notification::assertSentOnDemand(AccountInvitation::class, function (AccountInvitation $notification) use (&$token): bool {
        $mail = $notification->toMail(new stdClass);
        $token = basename(parse_url($mail->actionUrl, PHP_URL_PATH));

        return ! str_contains(implode(' ', $mail->introLines + $mail->outroLines), 'password:');
    });

    $this->get("/invitations/{$token}")->assertOk()->assertSee('Reviewer');
    $this->post("/invitations/{$token}", ['name' => 'Rev', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])
        ->assertRedirect('/app');

    $reviewer = User::query()->where('email', 'reviewer@example.test')->firstOrFail();
    expect($reviewer->roleIn($account))->toBe(MembershipRole::Reviewer)
        ->and(Invitation::query()->first()->accepted_at)->not->toBeNull();

    auth()->logout();
    $this->get("/invitations/{$token}")->assertOk()->assertSee('invalid, expired, or has already been used');
});

it('keeps at least one owner and restricts administration to owners', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $account->users()->attach($owner, ['role' => MembershipRole::Owner->value]);
    $account->users()->attach($viewer, ['role' => MembershipRole::Viewer->value]);
    $service = app(MembershipService::class);

    expect(fn () => $service->changeRole($account, $owner, $owner, MembershipRole::Viewer))->toThrow(ValidationException::class)
        ->and(fn () => $service->invite($account, $viewer, 'x@example.test', MembershipRole::Owner))->toThrow(AuthorizationException::class);
});
