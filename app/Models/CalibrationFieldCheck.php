<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalibrationFieldCheck extends Model
{
    use BelongsToAccount, HasPublicUuid, IsImmutable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'account_id', 'device_calibration_id', 'checked_at', 'reference_source', 'expected_level_db',
        'measured_level_db', 'passed', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'immutable_datetime',
            'passed' => 'boolean',
        ];
    }

    /** @return BelongsTo<DeviceCalibration, $this> */
    public function calibration(): BelongsTo
    {
        return $this->belongsTo(DeviceCalibration::class, 'device_calibration_id');
    }
}
