<?php

namespace App\Models;

use App\Enums\ConfigurationAckStatus;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceConfigAcknowledgment extends Model
{
    use BelongsToAccount;

    public $timestamps = false;

    protected $fillable = [
        'account_id', 'device_id', 'device_configuration_id', 'revision', 'status', 'reason',
        'reported_applied_at', 'received_at', 'request_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConfigurationAckStatus::class,
            'reported_applied_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<DeviceConfiguration, $this> */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(DeviceConfiguration::class, 'device_configuration_id');
    }
}
