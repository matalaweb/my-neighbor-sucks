<?php

namespace App\Http\Controllers\DeviceApi;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceCredential;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

abstract class DeviceApiController extends Controller
{
    /**
     * Device and account identity always come from the credential.
     */
    protected function device(Request $request): Device
    {
        /** @var DeviceCredential $credential */
        $credential = $request->user('device');

        return $credential->device;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        return $request->attributes->get('device_payload', []);
    }

    protected function receivedAt(Request $request): CarbonImmutable
    {
        return $request->attributes->get('server_received_at');
    }

    protected function requestId(Request $request): ?string
    {
        return $request->attributes->get('request_id');
    }
}
