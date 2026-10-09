<?php

namespace App\Http\Controllers\DeviceApi;

use App\Http\DeviceApi\DeviceApiResponse;
use App\Services\Devices\RegisterDeviceProvenance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The device registers its own measurement chain (profiles and calibrations).
 */
class ProvenanceController extends DeviceApiController
{
    public function store(Request $request, RegisterDeviceProvenance $registration): JsonResponse
    {
        return DeviceApiResponse::make($request, $registration->handle($this->device($request), $this->payload($request)));
    }
}
