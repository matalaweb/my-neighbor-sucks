<?php

namespace App\Models;

use App\Enums\CalibrationState;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * device + channel + deployment + profile + calibration identity. Series from
 * different streams are never averaged together.
 */
class MeasurementStream extends Model
{
    use BelongsToAccount;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'calibration_state' => CalibrationState::class,
            'first_captured_at' => 'immutable_datetime',
            'last_captured_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<DeviceDeployment, $this> */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(DeviceDeployment::class, 'device_deployment_id');
    }

    /** @return BelongsTo<MeasurementProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(MeasurementProfile::class, 'measurement_profile_id');
    }

    /** @return BelongsTo<DeviceCalibration, $this> */
    public function calibration(): BelongsTo
    {
        return $this->belongsTo(DeviceCalibration::class, 'device_calibration_id');
    }

    public static function keyFor(int $deviceId, string $channel, int $deploymentId, int $profileId, ?int $calibrationId): string
    {
        return hash('sha256', implode('|', [$deviceId, $channel, $deploymentId, $profileId, $calibrationId ?? 'none']));
    }

    public function label(): string
    {
        return $this->channel.' · placement r'.$this->deployment?->revision.' · profile r'.$this->profile?->revision
            .' · '.$this->calibration_state->getLabel();
    }
}
