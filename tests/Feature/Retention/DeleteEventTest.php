<?php

use App\Enums\MembershipRole;
use App\Models\AuditLog;
use App\Models\NoiseEvent;
use App\Models\User;
use App\Services\Retention\DeleteEvent;
use App\Services\Retention\KeepEvent;
use App\Services\Storage\EvidenceStorage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\DeviceFixture;
use Tests\Support\EvidenceScenario;

beforeEach(function (): void {
    $this->fixture = DeviceFixture::create();
    $start = CarbonImmutable::now()->subMinutes(10)->startOfMinute();
    EvidenceScenario::measurements($this->fixture, $start, 60);
    $this->event = EvidenceScenario::event($this->fixture, $start->addSeconds(20), 10);
    $this->recording = EvidenceScenario::verifiedRecording($this->event);
});

it('deletes an event in stages, leaving an audit tombstone with hashes', function (): void {
    $key = $this->recording->final_key;

    app(DeleteEvent::class)->handle($this->event, $this->fixture->owner, substr($this->event->uuid, 0, 8));

    expect(NoiseEvent::query()->whereKey($this->event->id)->exists())->toBeFalse()
        ->and(app(EvidenceStorage::class)->disk()->exists($key))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'recording.purged')->exists())->toBeTrue();

    $tombstone = AuditLog::query()->where('action', 'event.deleted_by_owner')->firstOrFail();
    expect($tombstone->metadata['recordings'][0]['verified_sha256'])->toBe($this->recording->verified_sha256)
        ->and($tombstone->user_id)->toBe($this->fixture->owner->id);
});

it('refuses kept events, wrong confirmations, and non-owners', function (): void {
    expect(fn () => app(DeleteEvent::class)->handle($this->event, $this->fixture->owner, 'nope'))->toThrow(ValidationException::class);

    $reviewer = User::factory()->create();
    $this->fixture->account->users()->attach($reviewer, ['role' => MembershipRole::Reviewer->value]);
    expect(fn () => app(DeleteEvent::class)->handle($this->event, $reviewer, substr($this->event->uuid, 0, 8)))->toThrow(AuthorizationException::class);

    app(KeepEvent::class)->set($this->event, true, $this->fixture->owner);
    expect(fn () => app(DeleteEvent::class)->handle($this->event->fresh(), $this->fixture->owner, substr($this->event->uuid, 0, 8)))->toThrow(ValidationException::class)
        ->and(NoiseEvent::query()->whereKey($this->event->id)->exists())->toBeTrue()
        ->and(app(EvidenceStorage::class)->disk()->exists($this->recording->final_key))->toBeTrue();
});
