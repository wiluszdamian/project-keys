<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductOfferFactory extends Factory
{
    public function definition(): array
    {
        $url = 'https://sklep.test/produkt-'.fake()->unique()->numberBetween(1, 1_000_000).'.html';

        return [
            'product_id' => Product::factory(),
            'url' => $url,
            'url_key' => sha1($url),
            'shop' => 'sklep.test',
            'price' => 1000,
            'currency' => 'PLN',
            'available' => true,
            'source' => 'manual',
            'is_primary' => true,
            'is_active' => true,
        ];
    }
}
