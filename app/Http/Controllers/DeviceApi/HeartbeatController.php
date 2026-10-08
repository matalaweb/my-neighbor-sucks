<?php

namespace App\Http\Controllers\DeviceApi;

use App\Http\DeviceApi\DeviceApiResponse;
use App\Services\Devices\RecordHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeartbeatController extends DeviceApiController
{
    public function store(Request $request, RecordHeartbeat $heartbeat): JsonResponse
    {
        return DeviceApiResponse::make($request, $heartbeat->handle($this->device($request), $this->payload($request), $this->receivedAt($request)));
    }
}
