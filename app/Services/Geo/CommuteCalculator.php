<?php

namespace App\Services\Geo;

use App\Models\CommutePlace;
use App\Models\Listing;
use App\Models\ListingCommute;
use Illuminate\Support\Collection;

/**
 * Czasy dojazdu z ofert do miejsc (praca itd.) autem i rowerem, w paczkach przez OSRM.
 */
class CommuteCalculator
{
    public function __construct(private Router $router) {}

    /**
     * @param  Collection<int, Listing>  $listings
     * @return int liczba zapisanych czasów
     */
    public function forListings(Collection $listings, ?Collection $places = null): int
    {
        $places ??= CommutePlace::usable()->get();
        $listings = $listings->filter(fn (Listing $listing) => $listing->hasCoordinates())->values();

        if ($places->isEmpty() || $listings->isEmpty()) {
            return 0;
        }

        $saved = 0;

        foreach ($listings->chunk(config('geo.batch')) as $chunk) {
            $chunk = $chunk->values();
            $sources = $chunk->map(fn (Listing $listing) => [$listing->lat, $listing->lng])->all();
            $destinations = $places->map(fn (CommutePlace $place) => [$place->lat, $place->lng])->values()->all();

            foreach (array_keys(ListingCommute::MODES) as $mode) {
                $matrix = $this->router->table($mode, $sources, $destinations);

                foreach ($matrix ?? [] as $i => $row) {
                    foreach ($row as $j => $cell) {
                        if ($cell === null) {
                            continue;
                        }

                        ListingCommute::updateOrCreate(
                            ['listing_id' => $chunk[$i]->id, 'commute_place_id' => $places->values()[$j]->id, 'mode' => $mode],
                            $cell,
                        );
                        $saved++;
                    }
                }
            }
        }

        return $saved;
    }

    /**
     * Krótki opis dojazdów do promptu AI i wiadomości, np. "Praca (ja): 8 min autem (3.2 km), 14 min rowerem".
     *
     * @return list<string>
     */
    public function describe(Listing $listing): array
    {
        return $listing->commutes()->with('place')->get()
            ->filter(fn (ListingCommute $commute) => $commute->place?->is_active)
            ->groupBy('commute_place_id')
            ->map(function (Collection $commutes) {
                $place = $commutes->first()->place;
                $parts = $commutes->sortBy(fn ($c) => array_search($c->mode, array_keys(ListingCommute::MODES)))
                    ->map(fn (ListingCommute $c) => "{$c->minutes} min ".ListingCommute::MODES[$c->mode].($c->mode === 'car' ? " ({$c->km} km)" : ''));

                return "{$place->name}: ".$parts->implode(', ').($place->max_minutes ? " (akceptowalnie do {$place->max_minutes} min)" : '');
            })
            ->values()
            ->all();
    }
}
