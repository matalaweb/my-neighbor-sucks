<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use BelongsToAccount, HasFactory, HasPublicUuid;

    protected $fillable = ['account_id', 'name', 'timezone', 'address', 'notes', 'archived_at'];

    protected function casts(): array
    {
        return [
            'address' => 'encrypted',
            'archived_at' => 'datetime',
        ];
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<NoiseEvent, $this> */
    public function noiseEvents(): HasMany
    {
        return $this->hasMany(NoiseEvent::class);
    }
}
