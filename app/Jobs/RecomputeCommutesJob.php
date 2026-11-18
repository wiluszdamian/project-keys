<?php

namespace App\Jobs;

use App\Models\CommutePlace;
use App\Models\Listing;
use App\Services\Geo\CommuteCalculator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Po zmianie miejsca dojazdu: od nowa czasy ze wszystkich aktualnych ofert do tego miejsca.
 */
class RecomputeCommutesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public int $placeId) {}

    public function uniqueId(): string
    {
        return (string) $this->placeId;
    }

    public function handle(CommuteCalculator $commutes): void
    {
        $place = CommutePlace::find($this->placeId);
        $place?->commutes()->delete();

        if (! $place || ! $place->is_active || $place->lat === null) {
            return;
        }

        Listing::query()->whereNull('duplicate_of_id')->where('last_seen_at', '>=', now()->subDays(30))->whereNotNull('lat')
            ->chunkById(config('geo.batch'), fn ($chunk) => $commutes->forListings($chunk, collect([$place])));
    }
}
