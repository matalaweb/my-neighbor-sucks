<?php

use App\Enums\ProvenanceSource;
use App\Filament\Resources\Devices\Pages\ViewDevice;
use App\Filament\Resources\Devices\RelationManagers\CalibrationsRelationManager;
use App\Filament\Resources\Devices\RelationManagers\ConfigurationsRelationManager;
use App\Filament\Resources\Devices\RelationManagers\ProfilesRelationManager;
use App\Services\Devices\DeviceConfigurationService;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\DeviceFixture;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-09T15:00:00Z');
    $this->fixture = DeviceFixture::create();
    $this->fixture->device->forceFill(['capabilities' => [
        'channels' => ['mic-1'],
        'metrics' => ['laeq_db', 'lafmax_db', 'lceq_db', 'lcpeak_db', 'low_frequency_leq_db', 'rms_dbfs'],
        'third_octave_bands' => false,
        'recording_formats' => ['audio/wav', 'audio/flac'],
    ]])->save();
    $this->actingAs($this->fixture->owner);
    Filament::setTenant($this->fixture->account);
});

function configurationsManager(DeviceFixture $fixture, string $relationManager = ConfigurationsRelationManager::class): Testable
{
    return Livewire::test($relationManager, ['ownerRecord' => $fixture->device->fresh(), 'pageClass' => ViewDevice::class]);
}

it('publishes channel settings without profile, placement or calibration selects', function (): void {
    $component = configurationsManager($this->fixture)->mountAction(TestAction::make('publish')->table());
    $channels = $component->get('mountedActions.0.data.data.channels');

    expect(array_values($channels))->toHaveCount(1)
        ->and(array_keys(array_values($channels)[0]))->toEqualCanonicalizing(['channel', 'enabled', 'metrics', 'bands_enabled']);

    $component->fillForm(['data.reporting_interval_seconds' => 60])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Published configuration r2; pending until the device acknowledges it.');

    $document = $this->fixture->device->configurations()->where('revision', 2)->sole()->document;

    expect($document['reporting_interval_seconds'])->toBe(60)
        ->and($document['channels'])->toBe([['channel' => 'mic-1', 'enabled' => true, 'metrics' => ['laeq_db', 'lafmax_db', 'lceq_db', 'lcpeak_db', 'low_frequency_leq_db', 'rms_dbfs'], 'bands_enabled' => false]]);
});

it('prefills the form from a revision that still references profiles, placements and calibrations', function (): void {
    $legacy = $this->fixture->configuration->document;
    $legacy['revision'] = 2;
    $legacy['channels'][0] += [
        'measurement_profile_id' => $this->fixture->profile->uuid,
        'deployment_id' => $this->fixture->deployment->uuid,
        'calibration_id' => $this->fixture->calibration->uuid,
        'calibration_state' => 'calibrated',
    ];
    $this->fixture->device->configurations()->create([
        'account_id' => $this->fixture->account->id, 'revision' => 2, 'document' => $legacy,
        'content_hash' => CanonicalJson::hash($legacy), 'issued_at' => CarbonImmutable::now(),
    ]);

    configurationsManager($this->fixture)
        ->callAction(TestAction::make('publish')->table())
        ->assertHasNoActionErrors();

    expect(array_keys($this->fixture->device->configurations()->where('revision', 3)->sole()->document['channels'][0]))
        ->toBe(['channel', 'enabled', 'metrics', 'bands_enabled']);
});

it('shows every publish validation error on its field and in a notification', function (): void {
    $this->fixture->device->forceFill(['capabilities' => [...$this->fixture->device->capabilities, 'metrics' => ['laeq_db', 'rms_dbfs']]])->save();

    $component = configurationsManager($this->fixture)
        ->mountAction(TestAction::make('publish')->table())
        ->fillForm([
            'data.pre_roll_seconds' => 500,
            'data.absolute_enabled' => true,
            'data.absolute_level_db' => null,
        ]);
    $channelKey = array_key_first($component->get('mountedActions.0.data.data.channels'));
    $component->callMountedAction();

    $component->assertHasActionErrors([
        'data.pre_roll_seconds' => 'Pre-roll must be 0–120 s.',
        'data.absolute_level_db',
        "data.channels.{$channelKey}.metrics",
    ]);

    Notification::assertNotified(
        Notification::make()
            ->title('The configuration was not published')
            ->body(new HtmlString('<ul class="list-disc ps-4"><li>The device has not reported support for lafmax_db.</li><li>The device has not reported support for lceq_db.</li><li>The device has not reported support for lcpeak_db.</li><li>The device has not reported support for low_frequency_leq_db.</li><li>Pre-roll must be 0–120 s.</li><li>Choose a level for the absolute rule, or disable it.</li></ul>'))
            ->danger()
            ->persistent(),
    );

    expect($this->fixture->device->configurations()->count())->toBe(1);
});

it('offers no owner create forms for profiles and calibrations and shows their source', function (string $relationManager, string $sourceRecord): void {
    configurationsManager($this->fixture, $relationManager)
        ->assertSuccessful()
        ->assertActionDoesNotExist(TestAction::make('create')->table())
        ->assertActionDoesNotExist(TestAction::make('revise')->table())
        ->assertTableColumnStateSet('source', ProvenanceSource::Device, $this->fixture->{$sourceRecord});
})->with([
    'profiles' => [ProfilesRelationManager::class, 'profile'],
    'calibrations' => [CalibrationsRelationManager::class, 'calibration'],
]);

it('no longer lets owners attach frequency-response files to calibrations', function (): void {
    configurationsManager($this->fixture, CalibrationsRelationManager::class)
        ->mountAction(TestAction::make('attach')->table($this->fixture->calibration))
        ->fillForm(['purpose' => 'frequency_response'])
        ->callMountedAction()
        ->assertHasActionErrors(['purpose']);

    expect($this->fixture->calibration->attachments()->count())->toBe(0);
});

it('publishes the maximum event duration and keeps it within bounds', function (): void {
    configurationsManager($this->fixture)
        ->mountAction(TestAction::make('publish')->table())
        ->assertSchemaStateSet(['data.max_event_duration_seconds' => 600])
        ->fillForm(['data.max_event_duration_seconds' => 1800])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($this->fixture->device->configurations()->where('revision', 2)->sole()->document['detection']['max_event_duration_seconds'])->toBe(1800);

    $service = app(DeviceConfigurationService::class);
    $settings = $service->settingsFromDocument($this->fixture->device->latestConfiguration()->document);

    expect(fn () => $service->publish($this->fixture->device, [...$settings, 'max_event_duration_seconds' => 30], $this->fixture->owner))
        ->toThrow(ValidationException::class);
});

it('defaults the maximum event duration for revisions published before it existed', function (): void {
    $legacy = $this->fixture->configuration->document;
    unset($legacy['detection']['max_event_duration_seconds']);

    expect(app(DeviceConfigurationService::class)->settingsFromDocument($legacy)['max_event_duration_seconds'])->toBe(600);
});
