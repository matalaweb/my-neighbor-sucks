<?php

namespace App\Http\Controllers\DeviceApi;

use App\Http\DeviceApi\DeviceApiResponse;
use App\Services\Events\IngestEventRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends DeviceApiController
{
    public function store(Request $request, IngestEventRevision $ingest): JsonResponse
    {
        $result = $ingest->handle($this->device($request), $this->payload($request), $this->receivedAt($request), $this->requestId($request));

        return DeviceApiResponse::make($request, $result['body'], $result['status']);
    }
}
