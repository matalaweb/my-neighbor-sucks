<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Tests\Support\DeviceFixture;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function devicePost(DeviceFixture $fixture, string $uri, array $payload): TestResponse
    {
        $response = $this->withHeaders($fixture->headers())->postJson('/api/v1/device/'.ltrim($uri, '/'), $payload);
        $this->resetGuard();

        return $response;
    }

    public function deviceGet(DeviceFixture $fixture, string $uri): TestResponse
    {
        $response = $this->withHeaders($fixture->headers())->getJson('/api/v1/device/'.ltrim($uri, '/'));
        $this->resetGuard();

        return $response;
    }

    /**
     * The auth middleware switches the default guard to "device" inside the
     * shared test application; restore it so later human requests behave
     * like fresh HTTP requests.
     */
    protected function resetGuard(): void
    {
        $this->app['auth']->shouldUse('web');
    }
}
