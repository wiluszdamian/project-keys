<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CommutePlaceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Praca',
            'address' => 'ul. Rejtana 36, Rzeszów',
            'lat' => 50.0231,
            'lng' => 22.0127,
            'max_minutes' => null,
            'is_active' => true,
        ];
    }
}
