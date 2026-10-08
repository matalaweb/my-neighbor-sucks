<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => 'Home',
            'timezone' => 'America/Chicago',
            'address' => fake()->streetAddress(),
        ];
    }
}
