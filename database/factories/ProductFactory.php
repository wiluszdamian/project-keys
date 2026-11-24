<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Wózek '.fake()->unique()->words(3, true),
            'is_active' => true,
        ];
    }
}
