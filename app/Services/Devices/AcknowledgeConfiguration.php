<?php

namespace App\Services\Devices;

use App\Enums\ConfigurationAckStatus;
use App\Http\DeviceApi\DeviceApiException;
use App\Http\DeviceApi\ErrorCode;
use App\Http\DeviceApi\SchemaVersion;
use App\Models\Device;
use App\Models\DeviceConfigAcknowledgment;
use App\Models\DeviceConfiguration;
use App\Services\Audit\AuditLogger;
use App\Support\Rfc3339;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AcknowledgeConfiguration
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(Device $device, array $payload, CarbonImmutable $receivedAt, ?string $requestId): array
    {
        SchemaVersion::assert($payload);

        $validator = Validator::make($payload, [
            'schema_version' => ['required', 'integer', 'in:'.config('noise.device_api.schema_version')],
            'revision' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:applied,rejected'],
            'reason' => ['nullable', 'string', 'max:2000', 'required_if:status,rejected'],
            'applied_at' => ['nullable', 'string'],
            'content_hash' => ['nullable', 'string', 'size:64'],
        ]);

        $errors = $validator->errors()->toArray();

        foreach (array_diff(array_keys($payload), ['schema_version', 'revision', 'status', 'reason', 'applied_at', 'content_hash']) as $unknown) {
            $errors[$unknown][] = 'Unknown field.';
        }

        if (($payload['applied_at'] ?? null) !== null && Rfc3339::parse($payload['applied_at']) === null) {
            $errors['applied_at'][] = 'Must be an RFC 3339 timestamp.';
        }

        if ($errors !== []) {
            throw new DeviceApiException(ErrorCode::ValidationFailed, 'The acknowledgment failed validation.', ['errors' => $errors]);
        }

        $configuration = DeviceConfiguration::query()->where('device_id', $device->id)->where('revision', $payload['revision'])->first();

        if ($configuration === null) {
            throw new DeviceApiException(ErrorCode::NotFound, 'Unknown configuration revision for this device.');
        }

        if (isset($payload['content_hash']) && ! hash_equals($configuration->content_hash, $payload['content_hash'])) {
            throw new DeviceApiException(ErrorCode::ValidationFailed, 'content_hash does not match the stored configuration revision.', [
                'errors' => ['content_hash' => ['Does not match revision '.$configuration->revision.'.']],
            ]);
        }

        $status = ConfigurationAckStatus::from($payload['status']);

        return DB::transaction(function () use ($device, $configuration, $status, $payload, $receivedAt, $requestId): array {
            $device = Device::query()->whereKey($device->id)->lockForUpdate()->first();
            $last = DeviceConfigAcknowledgment::query()->where('device_configuration_id', $configuration->id)->latest('id')->first();
            $duplicate = $last !== null && $last->status === $status && $last->reason === ($payload['reason'] ?? null);

            if (! $duplicate) {
                DeviceConfigAcknowledgment::query()->create([
                    'account_id' => $device->account_id,
                    'device_id' => $device->id,
                    'device_configuration_id' => $configuration->id,
                    'revision' => $configuration->revision,
                    'status' => $status,
                    'reason' => $payload['reason'] ?? null,
                    'reported_applied_at' => Rfc3339::parse($payload['applied_at'] ?? null),
                    'received_at' => $receivedAt,
                    'request_id' => $requestId,
                ]);

                if ($status === ConfigurationAckStatus::Applied) {
                    $device->forceFill(['applied_config_revision' => $configuration->revision])->save();
                }

                $this->audit->record('device.configuration.'.$status->value, $configuration, [
                    'revision' => $configuration->revision,
                    'reason' => $payload['reason'] ?? null,
                ], device: $device);
            }

            return ['status' => $duplicate ? 200 : 201, 'body' => [
                'revision' => $configuration->revision,
                'status' => $status->value,
                'recorded' => ! $duplicate,
                'desired_config_revision' => $device->desired_config_revision,
                'applied_config_revision' => $device->applied_config_revision,
            ]];
        });
    }
}
