<?php

namespace App\Models;

use App\Enums\MaintenanceJobKind;
use App\Enums\MaintenanceJobStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable dirty-bucket / outbox record. A queue dispatch is only a hint; the
 * reconciler processes any record left behind (spec §17).
 */
class MaintenanceJob extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => MaintenanceJobKind::class,
            'status' => MaintenanceJobStatus::class,
            'payload' => 'array',
            'bucket_start' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
        ];
    }
}
