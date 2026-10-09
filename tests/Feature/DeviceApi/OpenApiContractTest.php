<?php

use App\Http\DeviceApi\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\DeviceFixture;

function openApiSpec(): array
{
    return Yaml::parseFile(base_path('docs/openapi/device-api-v1.yaml'));
}

/**
 * Load a request fixture and substitute documented placeholders.
 *
 * @param  array<string, string>  $extra
 * @return array<string, mixed>
 */
function deviceFixturePayload(string $name, DeviceFixture $fixture, array $extra = []): array
{
    $json = file_get_contents(base_path('docs/openapi/fixtures/'.$name));

    $json = str_replace('"{{CONFIGURATION_REVISION}}"', (string) $fixture->configuration->revision, $json);
    $json = strtr($json, array_merge([
        '{{BOOT_ID}}' => $fixture->bootId,
        '{{PROFILE_ID}}' => $fixture->profile->uuid,
        '{{CALIBRATION_ID}}' => (string) $fixture->calibration?->uuid,
        '{{CONFIGURATION_SHA256}}' => $fixture->configuration->content_hash,
    ], $extra));

    expect($json)->not->toContain('{{');

    return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
}

it('parses as OpenAPI 3.1', function (): void {
    $spec = openApiSpec();

    expect($spec['openapi'])->toBe('3.1.0')
        ->and($spec['components']['securitySchemes']['deviceToken']['scheme'])->toBe('bearer');
});

it('documents every device API route with the right method', function (): void {
    $paths = openApiSpec()['paths'];

    $routes = collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/device/'));

    expect($routes)->toHaveCount(10)
        ->and($routes->map(fn (Route $route): string => $route->uri())->all())->toContain('api/v1/device/provenance')
        ->and($paths)->not->toHaveKey('/calibrations/{calibration_uuid}/attachments/{attachment_uuid}');

    foreach ($routes as $route) {
        $path = preg_replace(
            ['/\{eventUuid\}/', '/\{recordingUuid\}/'],
            ['{event_uuid}', '{recording_uuid}'],
            '/'.substr($route->uri(), strlen('api/v1/device/')),
        );
        $method = strtolower(collect($route->methods())->reject(fn (string $m): bool => $m === 'HEAD')->first());

        expect($paths)->toHaveKey($path)
            ->and($paths[$path])->toHaveKey($method);
    }

    expect(collect($paths)->map(fn (array $operations): int => count($operations))->sum())->toBe($routes->count());
});

it('documents every error code with its status, retry, and permanence', function (): void {
    $schema = openApiSpec()['components']['schemas']['ErrorCode'];

    foreach (ErrorCode::cases() as $code) {
        expect($schema['enum'])->toContain($code->value)
            ->and($schema['description'])->toContain(sprintf(
                '| %s | %d | %s | %s |',
                $code->value,
                $code->status(),
                $code->retry(),
                $code->permanent() ? 'true' : 'false',
            ));
    }

    expect($schema['enum'])->toHaveCount(count(ErrorCode::cases()));
});

it('references only fixture files that exist', function (): void {
    $yaml = file_get_contents(base_path('docs/openapi/device-api-v1.yaml'));
    preg_match_all('/externalValue: (\S+?) \}/', $yaml, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $file) {
        expect(base_path('docs/openapi/'.$file))->toBeFile();
        json_decode(file_get_contents(base_path('docs/openapi/'.$file)), flags: JSON_THROW_ON_ERROR);
    }
});

describe('request fixtures against the real API', function (): void {
    beforeEach(function (): void {
        Storage::fake('s3');
        CarbonImmutable::setTestNow('2026-10-08T12:20:00Z');
        $this->fixture = DeviceFixture::create(bands: [20, 25, 31.5, 40, 50, 63, 80, 100, 125]);
    });

    it('accepts the provenance registration fixture and replays it idempotently', function (): void {
        $registration = deviceFixturePayload('provenance-registration.json', $this->fixture);

        $this->devicePost($this->fixture, 'provenance', $registration)
            ->assertOk()
            ->assertJsonPath('measurement_profiles.0.status', 'created')
            ->assertJsonPath('calibrations.0.status', 'created');

        $this->devicePost($this->fixture, 'provenance', $registration)
            ->assertOk()
            ->assertJsonPath('measurement_profiles.0.status', 'existing')
            ->assertJsonPath('calibrations.0.status', 'existing');
    });

    it('serves a configuration without provenance references', function (): void {
        $response = $this->deviceGet($this->fixture, 'configuration')->assertOk()->assertJsonMissingPath('provenance');

        expect(array_keys($response->json('configuration.channels.0')))->toBe(['channel', 'enabled', 'metrics', 'bands_enabled']);
    });

    it('accepts both measurement batch fixtures and replays them idempotently', function (): void {
        $normal = deviceFixturePayload('measurement-batch.json', $this->fixture);
        $flagged = deviceFixturePayload('measurement-batch-flagged.json', $this->fixture);

        $this->devicePost($this->fixture, 'measurements/batches', $normal)->assertCreated()->assertJson(['inserted_count' => 3]);
        $this->devicePost($this->fixture, 'measurements/batches', $flagged)->assertCreated()->assertJson(['inserted_count' => 3]);
        $this->devicePost($this->fixture, 'measurements/batches', $normal)->assertOk()->assertJson(['replayed' => true]);
    });

    it('accepts the event, recording, and completion fixtures in order', function (): void {
        $this->devicePost($this->fixture, 'events', deviceFixturePayload('event-open.json', $this->fixture))
            ->assertCreated()->assertJson(['outcome' => 'stored', 'detection_state' => 'open']);

        $this->devicePost($this->fixture, 'events', deviceFixturePayload('event-finalized.json', $this->fixture))
            ->assertCreated()->assertJson(['outcome' => 'stored', 'current_revision' => 2, 'detection_state' => 'finalized']);

        $eventId = deviceFixturePayload('event-open.json', $this->fixture)['event_id'];
        $declaration = deviceFixturePayload('recording-declaration.json', $this->fixture);

        $declared = $this->devicePost($this->fixture, 'events/'.$eventId.'/recordings', $declaration)
            ->assertCreated()
            ->assertJsonStructure(['upload' => ['attempt_id', 'method', 'url', 'headers', 'expires_at']]);

        $completion = deviceFixturePayload('recording-completion.json', $this->fixture, ['{{ATTEMPT_ID}}' => $declared->json('upload.attempt_id')]);

        $this->devicePost($this->fixture, 'recordings/'.$declaration['recording_id'].'/complete', $completion)
            ->assertStatus(202)
            ->assertJson(['status' => 'uploaded', 'verified' => false, 'retain_local_copy' => true]);

        $this->deviceGet($this->fixture, 'recordings/'.$declaration['recording_id'])
            ->assertOk()->assertJsonPath('latest_attempt.state', 'completed');
    });

    it('accepts the heartbeat and configuration acknowledgment fixtures', function (): void {
        $this->devicePost($this->fixture, 'heartbeat', deviceFixturePayload('heartbeat.json', $this->fixture))
            ->assertOk()->assertJson(['configuration_pending' => false]);

        $this->deviceGet($this->fixture, 'configuration')
            ->assertOk()
            ->assertJson(['revision' => $this->fixture->configuration->revision, 'sha256' => $this->fixture->configuration->content_hash]);

        $this->devicePost($this->fixture, 'configuration/acknowledgments', deviceFixturePayload('configuration-acknowledgment.json', $this->fixture))
            ->assertCreated()->assertJson(['status' => 'applied', 'recorded' => true]);
    });
});
