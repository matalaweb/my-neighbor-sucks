<?php

namespace App\Http\Controllers\DeviceApi;

use App\Http\DeviceApi\DeviceApiResponse;
use App\Services\Recordings\CompleteRecordingUpload;
use App\Services\Recordings\IssueRecordingUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecordingController extends DeviceApiController
{
    public function declare(Request $request, string $eventUuid, IssueRecordingUpload $issuer): JsonResponse
    {
        $result = $issuer->declare($this->device($request), $eventUuid, $this->payload($request), $this->receivedAt($request));

        return DeviceApiResponse::make($request, $result['body'], $result['status']);
    }

    public function reissue(Request $request, string $recordingUuid, IssueRecordingUpload $issuer): JsonResponse
    {
        $result = $issuer->reissue($this->device($request), $recordingUuid, $this->receivedAt($request));

        return DeviceApiResponse::make($request, $result['body'], $result['status']);
    }

    public function complete(Request $request, string $recordingUuid, CompleteRecordingUpload $completion): JsonResponse
    {
        $result = $completion->handle($this->device($request), $recordingUuid, $this->payload($request), $this->receivedAt($request));

        return DeviceApiResponse::make($request, $result['body'], $result['status']);
    }

    public function show(Request $request, string $recordingUuid, IssueRecordingUpload $issuer, CompleteRecordingUpload $completion): JsonResponse
    {
        $recording = $issuer->recordingFor($this->device($request), $recordingUuid);

        return DeviceApiResponse::make($request, $completion->statusBody($recording));
    }
}
