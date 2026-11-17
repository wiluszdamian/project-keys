<?php

namespace Database\Factories;

use App\Models\ScrapeRun;
use App\Models\Search;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScrapeRun>
 */
class ScrapeRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'search_id' => Search::factory(),
            'portal' => 'otodom',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'status' => 'ok',
            'pages_fetched' => 1,
            'found_count' => 10,
            'new_count' => 2,
        ];
    }
}
