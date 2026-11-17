<?php

namespace Database\Factories;

use App\Models\Search;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Search>
 */
class SearchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Kraków wynajem 2 pokoje',
            'deal_type' => 'rent',
            'property_type' => 'flat',
            'city' => 'Kraków',
            'districts' => null,
            'price_min' => 2000,
            'price_max' => 4000,
            'area_min' => 35,
            'area_max' => 60,
            'rooms_min' => 2,
            'rooms_max' => 3,
            'portals' => ['otodom', 'olx'],
            'ai_preferences' => 'balkon, blisko tramwaju',
            'notify_channels' => ['telegram'],
            'min_score_to_notify' => 6,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
