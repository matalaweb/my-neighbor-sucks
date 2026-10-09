<?php

namespace App\Http\Controllers\DeviceApi;

use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\ErrorCode;
use App\Models\Attachment;
use App\Models\DeviceCalibration;
use App\Services\Storage\EvidenceStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lets a device fetch the microphone frequency-response file of one of its own calibration
 * records (e.g. a miniDSP UMIK-1 serial-specific file), so it can verify the SHA-256 listed in
 * configuration provenance and build its response correction. Other attachment purposes
 * (certificates, photos) are never served to devices.
 */
class CalibrationAttachmentController extends DeviceApiController
{
    public function show(Request $request, string $calibrationUuid, string $attachmentUuid, EvidenceStorage $storage): StreamedResponse
    {
        $calibration = DeviceCalibration::query()
            ->where('device_id', $this->device($request)->id)
            ->where('uuid', strtolower($calibrationUuid))
            ->first();

        $attachment = $calibration?->attachments()
            ->where('uuid', strtolower($attachmentUuid))
            ->where('purpose', DeviceCalibration::DEVICE_ATTACHMENT_PURPOSE)
            ->first();

        if (! $attachment instanceof Attachment || ! $storage->disk()->exists($attachment->object_key)) {
            throw new DeviceApiException(ErrorCode::NotFound, 'Unknown calibration attachment for this device.');
        }

        return $storage->disk()->download($attachment->object_key, $attachment->original_filename, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-SHA256' => $attachment->sha256,
            'Cache-Control' => 'no-store',
        ]);
    }
}
