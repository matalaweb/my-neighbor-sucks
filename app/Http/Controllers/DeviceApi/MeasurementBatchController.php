<?php

namespace App\Http\Controllers\DeviceApi;

use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\DeviceApiResponse;
use App\Services\Ingestion\IngestMeasurements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeasurementBatchController extends DeviceApiController
{
    public function store(Request $request, IngestMeasurements $ingest): JsonResponse
    {
        try {
            $result = $ingest->handle($this->device($request), $this->payload($request), $this->receivedAt($request), $this->requestId($request));
        } catch (DeviceApiException $exception) {
            $request->attributes->set('rejected_rows', count($this->payload($request)['records'] ?? []));

            throw $exception;
        }

        return DeviceApiResponse::make($request, [
            ...$result->body,
            'replayed' => $result->replayed,
        ], $result->replayed ? 200 : 201);
    }
}
