<?php

namespace App\Http\DeviceApi;

use App\Models\DeviceCredential;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Resolves the device credential for the "device" guard. Every request
 * re-reads the credential, so revocation takes effect immediately.
 */
class DeviceTokenResolver
{
    public const TOKEN_PREFIX = 'nmd_';

    public function __invoke(Request $request): ?DeviceCredential
    {
        $token = $request->bearerToken();

        if ($token === null || ! str_starts_with($token, self::TOKEN_PREFIX) || strlen($token) > 128) {
            return null;
        }

        $credential = DeviceCredential::query()
            ->with('device')
            ->where('token_hash', DeviceCredential::hashSecret($token))
            ->first();

        if ($credential === null || ! $credential->isActive() || $credential->device === null || $credential->device->isArchived()) {
            return null;
        }

        $this->touch($credential, $request);

        return $credential;
    }

    private function touch(DeviceCredential $credential, Request $request): void
    {
        $now = CarbonImmutable::now();
        $interval = (int) config('noise.device_api.last_used_write_interval_seconds');

        if ($credential->last_used_at !== null && $credential->last_used_at->diffInSeconds($now) < $interval) {
            return;
        }

        DeviceCredential::query()->whereKey($credential->getKey())->update([
            'last_used_at' => $now,
            'last_used_ip' => $request->ip(),
        ]);
    }
}
