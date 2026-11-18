<?php

namespace Database\Factories;

use App\Models\Listing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    public function definition(): array
    {
        $price = fake()->numberBetween(2000, 5000);
        $area = fake()->numberBetween(30, 70);

        return [
            'portal' => 'otodom',
            'external_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'url' => fake()->url(),
            'title' => 'Mieszkanie '.$area.' m2',
            'deal_type' => 'rent',
            'price' => $price,
            'rent_extra' => null,
            'area' => $area,
            'price_per_m2' => intdiv($price, $area),
            'rooms' => 2,
            'floor' => '3',
            'city' => 'Kraków',
            'district' => 'Krowodrza',
            'street' => null,
            'is_private_owner' => true,
            'description' => 'Opis mieszkania.',
            'image_url' => null,
            // Strona ogłoszenia już pobrana, więc testy oceny nie wychodzą do portalu.
            'raw' => ['enriched_at' => '2026-01-01T00:00:00+00:00'],
            'content_hash' => fake()->sha1(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
