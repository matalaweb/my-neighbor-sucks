<?php

use App\Models\Attachment;
use App\Models\DeviceCalibration;
use App\Services\Devices\DeviceConfigurationService;
use App\Services\Storage\AttachmentStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DeviceFixture;

beforeEach(function (): void {
    Storage::fake('s3');
    CarbonImmutable::setTestNow('2026-10-09T12:00:00Z');
    $this->fixture = DeviceFixture::create();
});

function storeCalibrationFile(DeviceCalibration $calibration, string $contents, string $name, string $purpose): Attachment
{
    $path = tempnam(sys_get_temp_dir(), 'cal');
    file_put_contents($path, $contents);

    return app(AttachmentStore::class)->store($calibration, $path, $name, 'text/plain', $purpose, null);
}

it('lists frequency-response files in provenance and serves the exact bytes to the owning device', function (): void {
    $umik = "\"Sens Factor =-0.7dB, AGain =18dB, SERNO: 7103946\"\n10.054\t-1.70\n1000.0\t0.00\n";
    $file = storeCalibrationFile($this->fixture->calibration, $umik, '7103946_90deg.txt', 'frequency_response');
    storeCalibrationFile($this->fixture->calibration, 'certificate', 'certificate.pdf', 'certificate');

    $listed = $this->deviceGet($this->fixture, 'configuration')->assertOk()->json('provenance.calibrations.0.attachments');

    expect($listed)->toHaveCount(1)
        ->and($listed[0])->toMatchArray([
            'id' => $file->uuid,
            'purpose' => 'frequency_response',
            'filename' => '7103946_90deg.txt',
            'byte_size' => strlen($umik),
            'sha256' => hash('sha256', $umik),
        ]);

    $response = $this->deviceGet($this->fixture, ltrim(substr($listed[0]['download_path'], strlen('/api/v1/device/')), '/'))
        ->assertOk()
        ->assertHeader('X-Content-SHA256', hash('sha256', $umik));

    expect($response->streamedContent())->toBe($umik)
        ->and($response->headers->get('X-Request-Id'))->not->toBeEmpty();
});

it('never serves other attachment purposes or another device\'s calibration files', function (): void {
    $certificate = storeCalibrationFile($this->fixture->calibration, 'certificate', 'certificate.pdf', 'certificate');
    $this->deviceGet($this->fixture, "calibrations/{$this->fixture->calibration->uuid}/attachments/{$certificate->uuid}")
        ->assertNotFound()->assertJsonPath('error.code', 'not_found');

    $other = DeviceFixture::create();
    $foreign = storeCalibrationFile($other->calibration, 'curve', 'other.txt', 'frequency_response');
    $this->deviceGet($this->fixture, "calibrations/{$other->calibration->uuid}/attachments/{$foreign->uuid}")
        ->assertNotFound();
    $this->deviceGet($this->fixture, "calibrations/{$this->fixture->calibration->uuid}/attachments/{$foreign->uuid}")
        ->assertNotFound();
});

it('keeps the configuration hash unchanged when a file is attached later', function (): void {
    $before = $this->deviceGet($this->fixture, 'configuration')->json('sha256');
    storeCalibrationFile($this->fixture->calibration, 'curve', 'c.txt', 'frequency_response');

    expect($this->deviceGet($this->fixture, 'configuration')->json('sha256'))->toBe($before)
        ->and(app(DeviceConfigurationService::class))->toBeInstanceOf(DeviceConfigurationService::class);
});
