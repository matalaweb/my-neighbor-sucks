<?php

namespace Database\Factories;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'account_id' => fn (array $attributes): int => Property::query()->find($attributes['property_id'])->account_id,
            'name' => 'Pi 4B — '.fake()->word(),
            'status' => DeviceStatus::Active,
            'reporting_interval_seconds' => 30,
            'heartbeat_interval_seconds' => 60,
        ];
    }
}
