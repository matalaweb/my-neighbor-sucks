<?php

namespace App\Models;

use App\Enums\CalibrationState;
use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\IsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Immutable calibration record for a device channel. A frequency-response
 * file alone never establishes absolute SPL calibration (spec §4). No
 * accuracy or uncertainty value is ever invented.
 */
class DeviceCalibration extends Model
{
    use BelongsToAccount, HasPublicUuid, IsImmutable;

    public const UPDATED_AT = null;

    /** Attachments with this purpose are listed in device provenance and downloadable by the device. */
    public const DEVICE_ATTACHMENT_PURPOSE = 'frequency_response';

    protected $fillable = [
        'account_id', 'device_id', 'channel', 'revision', 'calibration_state', 'reference_method',
        'reference_device', 'reference_level_db', 'reference_frequency_hz', 'sensitivity_mv_per_pa',
        'sensitivity_dbfs_at_94db', 'gain_configuration', 'application_method', 'correction_metadata',
        'performed_at', 'performed_by', 'notes', 'content_hash', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'calibration_state' => CalibrationState::class,
            'correction_metadata' => 'array',
            'performed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return HasMany<CalibrationFieldCheck, $this> */
    public function fieldChecks(): HasMany
    {
        return $this->hasMany(CalibrationFieldCheck::class);
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function label(): string
    {
        return 'Calibration r'.$this->revision.' — '.$this->channel.' · '.$this->calibration_state->getLabel().' · '.$this->reference_method;
    }
}
