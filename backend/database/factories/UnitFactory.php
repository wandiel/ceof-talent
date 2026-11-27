<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Unit> */
class UnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Unidade '.fake()->unique()->city(),
            'code' => strtoupper(fake()->unique()->bothify('UN-###')),
            'city' => fake()->city(),
            'state' => fake()->randomElement(['AM', 'PA', 'SP', 'RJ']),
            'is_active' => true,
        ];
    }
}
