<?php

namespace Database\Factories;

use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\Search;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListingAnalysis>
 */
class ListingAnalysisFactory extends Factory
{
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'search_id' => Search::factory(),
            'model' => 'test-model',
            'score' => 7,
            'summary' => 'Dobra oferta.',
            'pros' => ['balkon'],
            'red_flags' => [],
        ];
    }
}
