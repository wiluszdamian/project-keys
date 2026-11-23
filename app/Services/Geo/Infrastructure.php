<?php

namespace App\Services\Geo;

use App\Models\Listing;
use App\Models\Poi;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Najbliższe sklepy (najbliższy z każdej dużej sieci), żłobek, przedszkole, szkoła podstawowa i średnia.
 * Kandydatów wybieramy w linii prostej, odległość i czas drogą liczy nasz OSRM (profil rowerowy, bo ma ścieżki;
 * czas pieszo z długości trasy). Zapis w listings.infrastructure.
 */
class Infrastructure
{
    public function __construct(private Router $router, private Geocoder $geocoder) {}

    /**
     * Liczy i zapisuje. False, gdy oferta nie ma współrzędnych albo nie ma punktów w bazie.
     */
    public function refresh(Listing $listing): bool
    {
        if (! $listing->hasCoordinates() || ! Poi::query()->exists()) {
            return false;
        }

        $nearest = [
            Poi::SHOP => $this->shops($listing),
            Poi::NURSERY => $this->nearest($listing, Poi::NURSERY, 1),
            Poi::KINDERGARTEN => $this->nearest($listing, Poi::KINDERGARTEN, 1),
            Poi::PRIMARY => $this->nearest($listing, Poi::PRIMARY, 1),
            Poi::SECONDARY => $this->nearest($listing, Poi::SECONDARY, 1),
            Poi::PEDIATRIC => $this->nearest($listing, Poi::PEDIATRIC, 1),
            Poi::CLINIC => $this->nearest($listing, Poi::CLINIC, 1),
            Poi::HOSPITAL => $this->places($listing),
        ];

        $all = collect($nearest)->flatten(1)->values();
        $points = $all->map(fn (array $poi) => [$poi['lat'], $poi['lng']])->all();
        $routes = $all->isEmpty() ? [] : ($this->router->table('bike', [[$listing->lat, $listing->lng]], $points)[0] ?? []);
        $carRoutes = $all->isEmpty() ? [] : ($this->router->table('car', [[$listing->lat, $listing->lng]], $points)[0] ?? []);
        $walkKmh = (float) config('infrastructure.walk_kmh');

        $withRoutes = $all->map(function (array $poi, int $i) use ($routes, $carRoutes, $walkKmh) {
            $route = $routes[$i] ?? null;
            $km = isset($route['meters']) ? round($route['meters'] / 1000, 3) : round($poi['line_km'] * 1.3, 3);

            return [
                'name' => $poi['name'],
                'address' => $poi['address'],
                'km' => $km,
                'by_road' => $route !== null,
                'walk_min' => (int) max(1, round($km / $walkKmh * 60)),
                'bike_min' => $route['minutes'] ?? null,
                'car_min' => $carRoutes[$i]['minutes'] ?? null,
            ];
        });

        $offset = 0;
        $items = [];

        foreach ($nearest as $category => $pois) {
            $items[$category] = $withRoutes->slice($offset, count($pois))->values()->all();
            $offset += count($pois);
        }

        $listing->forceFill([
            'infrastructure' => ['items' => $items, 'precision' => $listing->geo_precision],
            'infrastructure_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Linie do promptu AI i asystenta, np. "Sklepy: Biedronka 0,4 km (pieszo 5 min), Lidl 1,2 km".
     *
     * @return list<string>
     */
    public static function lines(Listing $listing): array
    {
        $items = $listing->infrastructure['items'] ?? null;

        if (! $items) {
            return [];
        }

        $lines = [];

        foreach (Poi::LABELS as $category => $label) {
            $pois = $items[$category] ?? [];
            $lines[] = $label.': '.($pois === [] ? 'brak w promieniu '.config('infrastructure.search_km').' km' : collect($pois)
                ->map(fn (array $poi) => $poi['name'].' '.self::km($poi['km']).($category === Poi::HOSPITAL && $poi['car_min'] ? ' (autem '.$poi['car_min'].' min)' : ' (pieszo '.$poi['walk_min'].' min)'))
                ->implode(', '));
        }

        return $lines;
    }

    public static function km(float $km): string
    {
        return $km < 1 ? ((int) round($km * 1000, -1)).' m' : str_replace('.', ',', (string) round($km, 1)).' km';
    }

    /**
     * Stałe miejsca z config (np. szpital przy Rycerskiej), położenie z OpenStreetMap (cache).
     *
     * @return list<array<string, mixed>>
     */
    private function places(Listing $listing): array
    {
        $places = [];

        foreach (config('infrastructure.places', []) as $place) {
            $point = $this->geocoder->geocode($place['address']);

            if ($point !== null) {
                $places[] = [
                    'name' => $place['name'],
                    'address' => $place['address'],
                    'lat' => $point['lat'],
                    'lng' => $point['lng'],
                    'line_km' => SearchArea::distanceKm($listing->lat, $listing->lng, $point['lat'], $point['lng']),
                ];
            }
        }

        return $places;
    }

    /**
     * Najbliższy sklep każdej dużej sieci, potem inne supermarkety, razem najwyżej infrastructure.shops.
     *
     * @return list<array<string, mixed>>
     */
    private function shops(Listing $listing): array
    {
        $brands = config('infrastructure.brands');
        $candidates = $this->candidates($listing, Poi::SHOP)->map(function (array $poi) use ($brands) {
            $label = $poi['brand'] ?: $poi['name'] ?: 'Supermarket';
            $poi['chain'] = collect($brands)->first(fn (string $brand) => Str::contains(Str::lower($label), Str::lower($brand))) ?? $label;
            $poi['name'] = $poi['chain'];

            return $poi;
        });

        return $candidates
            ->unique('chain')
            ->sortBy(fn (array $poi) => [in_array($poi['chain'], $brands, true) ? 0 : 1, $poi['line_km']])
            ->take((int) config('infrastructure.shops'))
            ->sortBy('line_km')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nearest(Listing $listing, string $category, int $count): array
    {
        return $this->candidates($listing, $category)->take($count)->values()->all();
    }

    /**
     * Punkty kategorii w kwadracie wokół oferty, od najbliższego w linii prostej.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function candidates(Listing $listing, string $category): Collection
    {
        $radius = (float) config('infrastructure.search_km');
        $dLat = $radius / 111.0;
        $dLng = $radius / (111.0 * cos(deg2rad($listing->lat)));

        return Poi::query()
            ->where('category', $category)
            ->whereBetween('lat', [$listing->lat - $dLat, $listing->lat + $dLat])
            ->whereBetween('lng', [$listing->lng - $dLng, $listing->lng + $dLng])
            ->get()
            ->map(fn (Poi $poi) => [
                'name' => $poi->name ?: Poi::LABELS[$category],
                'brand' => $poi->brand,
                'address' => $poi->address,
                'lat' => $poi->lat,
                'lng' => $poi->lng,
                'line_km' => SearchArea::distanceKm($listing->lat, $listing->lng, $poi->lat, $poi->lng),
            ])
            ->filter(fn (array $poi) => $poi['line_km'] <= $radius)
            ->sortBy('line_km')
            ->values();
    }
}
