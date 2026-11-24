<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProductShopFactory extends Factory
{
    public function definition(): array
    {
        return [
            'domain' => fake()->unique()->domainWord().'.test',
            'is_active' => true,
            'source' => 'manual',
        ];
    }
}
