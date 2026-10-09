<?php

use App\Http\Controllers\DeviceApi\CalibrationAttachmentController;
use App\Http\Controllers\DeviceApi\ConfigurationController;
use App\Http\Controllers\DeviceApi\EventController;
use App\Http\Controllers\DeviceApi\HeartbeatController;
use App\Http\Controllers\DeviceApi\MeasurementBatchController;
use App\Http\Controllers\DeviceApi\RecordingController;
use App\Models\DeviceCredential;
use Illuminate\Support\Facades\Route;

/*
| Device API v1 (docs/openapi/device-api-v1.yaml). Device and account
| identity are inferred from the bearer credential, never from the body.
*/
Route::prefix('v1/device')
    ->middleware(['device.context', 'auth:device'])
    ->name('device.')
    ->group(function (): void {
        Route::post('measurements/batches', [MeasurementBatchController::class, 'store'])
            ->middleware(['device.ability:'.DeviceCredential::ABILITY_MEASUREMENTS, 'throttle:device-measurements', 'device.payload'])
            ->name('measurements.batches.store');

        Route::post('events', [EventController::class, 'store'])
            ->middleware(['device.ability:'.DeviceCredential::ABILITY_EVENTS, 'throttle:device-events', 'device.payload'])
            ->name('events.store');

        Route::middleware(['device.ability:'.DeviceCredential::ABILITY_RECORDINGS, 'throttle:device-recordings'])->group(function (): void {
            Route::post('events/{eventUuid}/recordings', [RecordingController::class, 'declare'])->middleware('device.payload')->name('recordings.declare');
            Route::post('recordings/{recordingUuid}/upload-attempts', [RecordingController::class, 'reissue'])->name('recordings.upload-attempts.store');
            Route::post('recordings/{recordingUuid}/complete', [RecordingController::class, 'complete'])->middleware('device.payload')->name('recordings.complete');
            Route::get('recordings/{recordingUuid}', [RecordingController::class, 'show'])->name('recordings.show');
        });

        Route::get('configuration', [ConfigurationController::class, 'show'])
            ->middleware(['device.ability:'.DeviceCredential::ABILITY_CONFIGURATION, 'throttle:device-control'])
            ->name('configuration.show');

        Route::get('calibrations/{calibrationUuid}/attachments/{attachmentUuid}', [CalibrationAttachmentController::class, 'show'])
            ->middleware(['device.ability:'.DeviceCredential::ABILITY_CONFIGURATION, 'throttle:device-control'])
            ->name('calibrations.attachments.show');

        Route::post('configuration/acknowledgments', [ConfigurationController::class, 'acknowledge'])
            ->middleware(['device.ability:'.DeviceCredential::ABILITY_CONFIGURATION, 'throttle:device-control', 'device.payload'])
            ->name('configuration.acknowledgments.store');

        Route::post('heartbeat', [HeartbeatController::class, 'store'])
            ->middleware(['device.ability:'.DeviceCredential::ABILITY_HEARTBEAT, 'throttle:device-control', 'device.payload'])
            ->name('heartbeat.store');
    });
