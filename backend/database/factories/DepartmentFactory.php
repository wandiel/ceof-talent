<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Department> */
class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Departamento '.fake()->unique()->word().' '.fake()->unique()->numberBetween(1, 99999),
            'code' => null,
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
