<?php

namespace App\Http\Controllers\DeviceApi;

use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\DeviceApiResponse;
use App\Http\DeviceApi\ErrorCode;
use App\Services\Devices\AcknowledgeConfiguration;
use App\Support\Rfc3339;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigurationController extends DeviceApiController
{
    /**
     * The configuration carries operational settings only; the device's measurement
     * chain is registered by the device itself (POST /provenance).
     */
    public function show(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $configuration = $device->latestConfiguration();

        if ($configuration === null) {
            throw new DeviceApiException(ErrorCode::NotFound, 'No configuration has been published for this device yet.');
        }

        return DeviceApiResponse::make($request, [
            'revision' => $configuration->revision,
            'sha256' => $configuration->content_hash,
            'issued_at' => Rfc3339::format($configuration->issued_at),
            'applied_revision' => $device->applied_config_revision,
            'configuration' => $configuration->document,
        ], 200, ['ETag' => '"'.$configuration->content_hash.'"']);
    }

    public function acknowledge(Request $request, AcknowledgeConfiguration $acknowledge): JsonResponse
    {
        $result = $acknowledge->handle($this->device($request), $this->payload($request), $this->receivedAt($request), $this->requestId($request));

        return DeviceApiResponse::make($request, $result['body'], $result['status']);
    }
}
