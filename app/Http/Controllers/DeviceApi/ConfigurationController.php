<?php

namespace App\Http\Controllers\DeviceApi;

use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\DeviceApiResponse;
use App\Http\DeviceApi\ErrorCode;
use App\Services\Devices\AcknowledgeConfiguration;
use App\Services\Devices\DeviceConfigurationService;
use App\Support\Rfc3339;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigurationController extends DeviceApiController
{
    public function show(Request $request, DeviceConfigurationService $configurations): JsonResponse
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
            'provenance' => $configurations->provenance($device, $configuration->document),
        ], 200, ['ETag' => '"'.$configuration->content_hash.'"']);
    }

    public function acknowledge(Request $request, AcknowledgeConfiguration $acknowledge): JsonResponse
    {
        $result = $acknowledge->handle($this->device($request), $this->payload($request), $this->receivedAt($request), $this->requestId($request));

        return DeviceApiResponse::make($request, $result['body'], $result['status']);
    }
}
