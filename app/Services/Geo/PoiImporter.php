<?php

namespace App\Services\Geo;

use App\Models\Poi;
use App\Models\Search;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sklepy, żłobki, przedszkola i szkoły z OpenStreetMap (Overpass API) dla obszaru wokół aktywnych wyszukiwań.
 * Jedno zapytanie o cały prostokąt, bez adresów ofert. Wynik zastępuje poprzedni import.
 */
class PoiImporter
{
    private const SKIP_SCHOOL = '/muzyczn|plastyczn|baletow|jazdy|j[eę]zyk|ta[nń]ca|policealn|wy[zż]sz|uczelni|uniwersytet|akademi|politechnik|specjaln|sportow\w*\s+klub|doros[lł]ych|kursy/u';

    public function __construct(private SearchArea $area) {}

    /**
     * @return array{total: int, by_category: array<string, int>, bbox: array{0: float, 1: float, 2: float, 3: float}}
     */
    public function import(): array
    {
        $bbox = $this->bbox();
        $query = sprintf(
            '[out:json][timeout:180];(nwr["shop"~"^(supermarket|hypermarket)$"](%1$s);nwr["amenity"~"^(kindergarten|childcare|school|clinic|doctors)$"](%1$s);nwr["healthcare"~"^(clinic|doctor|centre)$"](%1$s););out center tags;',
            implode(',', array_map(fn (float $value) => number_format($value, 4, '.', ''), $bbox)),
        );

        $response = Http::timeout(200)
            ->withHeaders(['User-Agent' => config('geo.nominatim.user_agent')])
            ->asForm()
            ->post(config('infrastructure.overpass_url'), ['data' => $query]);

        if (! $response->successful() || ! is_array($response->json('elements'))) {
            throw new RuntimeException('Overpass: błąd '.$response->status().' '.mb_substr($response->body(), 0, 200));
        }

        $rows = collect($response->json('elements'))->flatMap(fn (array $element) => $this->rows($element))->unique(fn (array $row) => $row['osm_id'].'|'.$row['category'])->values();

        DB::transaction(function () use ($rows) {
            Poi::query()->delete();

            foreach ($rows->chunk(500) as $chunk) {
                Poi::insert($chunk->map(fn (array $row) => $row + ['created_at' => now(), 'updated_at' => now()])->all());
            }
        });

        return ['total' => $rows->count(), 'by_category' => $rows->countBy('category')->all(), 'bbox' => $bbox];
    }

    /**
     * Prostokąt [południe, zachód, północ, wschód] wokół środków aktywnych wyszukiwań.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function bbox(): array
    {
        $centers = Search::active()->get()->map(fn (Search $search) => $this->area->center($search))->filter()->values();

        if ($centers->isEmpty()) {
            throw new RuntimeException('Brak aktywnych wyszukiwań z położeniem miasta.');
        }

        $margin = (float) config('infrastructure.margin_km') + (float) Search::active()->max('radius_km');
        $dLat = $margin / 111.0;
        $midLat = deg2rad($centers->avg('lat'));
        $dLng = $margin / (111.0 * cos($midLat));

        return [
            round($centers->min('lat') - $dLat, 4),
            round($centers->min('lng') - $dLng, 4),
            round($centers->max('lat') + $dLat, 4),
            round($centers->max('lng') + $dLng, 4),
        ];
    }

    /**
     * Jeden element OSM może dać kilka wierszy (np. "Żłobek i Przedszkole").
     *
     * @return list<array<string, mixed>>
     */
    public function rows(array $element): array
    {
        $tags = $element['tags'] ?? [];
        $lat = $element['lat'] ?? $element['center']['lat'] ?? null;
        $lng = $element['lon'] ?? $element['center']['lon'] ?? null;

        if ($lat === null || $lng === null) {
            return [];
        }

        $name = trim((string) ($tags['name'] ?? ''));
        $lower = mb_strtolower($name);
        $base = [
            'osm_id' => ($element['type'] ?? 'x').'/'.($element['id'] ?? '0'),
            'name' => $name !== '' ? mb_substr($name, 0, 250) : null,
            'brand' => isset($tags['brand']) ? mb_substr((string) $tags['brand'], 0, 250) : null,
            'address' => $this->address($tags),
            'lat' => (float) $lat,
            'lng' => (float) $lng,
        ];

        return array_map(fn (string $category) => ['category' => $category] + $base, $this->categories($tags, $lower));
    }

    /**
     * @return list<string>
     */
    private function categories(array $tags, string $name): array
    {
        if (in_array($tags['shop'] ?? null, ['supermarket', 'hypermarket'], true)) {
            return [Poi::SHOP];
        }

        $amenity = $tags['amenity'] ?? null;

        if ($amenity === 'childcare' || $amenity === 'kindergarten') {
            $categories = [];

            if ($amenity === 'childcare' || str_contains($name, 'żłob') || str_contains($name, 'zlob')) {
                $categories[] = Poi::NURSERY;
            }

            if ($amenity === 'kindergarten' && ($categories === [] || str_contains($name, 'przedszk'))) {
                $categories[] = Poi::KINDERGARTEN;
            }

            return $categories;
        }

        if (in_array($amenity, ['clinic', 'doctors'], true) || in_array($tags['healthcare'] ?? null, ['clinic', 'doctor', 'centre'], true)) {
            return $this->healthCategories($tags, $name, $amenity);
        }

        if ($amenity !== 'school' || preg_match(self::SKIP_SCHOOL, $name) === 1) {
            return [];
        }

        $isced = (string) ($tags['isced:level'] ?? '');
        $categories = [];

        if (preg_match('/szko[lł]\w*\s+podstawow|\bsp\b|\bsp\s*nr|podstawowa/u', $name) === 1 || preg_match('/(^|[;,\s])1([;,\s-]|$)/', $isced) === 1) {
            $categories[] = Poi::PRIMARY;
        }

        if (preg_match('/liceum|technikum|zesp[oó][lł]\s+szk[oó][lł]|bran[zż]ow|\blo\b|szko[lł]a\s+[sś]redni|centrum\s+kszta[lł]cenia/u', $name) === 1 || preg_match('/(^|[;,\s-])3([;,\s]|$)/', $isced) === 1) {
            $categories[] = Poi::SECONDARY;
        }

        return $categories;
    }

    /**
     * Przychodnia z pediatrą (tag healthcare:speciality=paediatrics albo pediatria w nazwie) albo zwykła przychodnia POZ.
     * Gabinety jednej specjalności (dentysta, ginekolog) pomijamy.
     *
     * @return list<string>
     */
    private function healthCategories(array $tags, string $name, ?string $amenity): array
    {
        $speciality = mb_strtolower((string) ($tags['healthcare:speciality'] ?? ''));

        if (str_contains($speciality, 'paediatric') || str_contains($speciality, 'pediatric') || preg_match('/pediatr|dzieci[eę]c|dla\s+dzieci/u', $name) === 1) {
            return [Poi::PEDIATRIC, Poi::CLINIC];
        }

        $general = preg_match('/przychodni|nzoz|\bpoz\b|o[sś]rodek\s+zdrowia|centrum\s+medyczn|lekarz\w*\s+rodzinn|medycyny\s+rodzinnej|podstawowej\s+opieki/u', $name) === 1
            || str_contains($speciality, 'general');

        return $general || ($amenity === 'clinic' && $speciality === '' && preg_match('/stomatolog|dent|ortodon|rehabilit|weteryn|okulist|ginekolog|laborator/u', $name) !== 1)
            ? [Poi::CLINIC]
            : [];
    }

    private function address(array $tags): ?string
    {
        $street = trim((string) ($tags['addr:street'] ?? $tags['addr:place'] ?? ''));
        $number = trim((string) ($tags['addr:housenumber'] ?? ''));
        $city = trim((string) ($tags['addr:city'] ?? ''));
        $address = trim(implode(', ', array_filter([trim($street.' '.$number), $city])));

        return $address !== '' ? mb_substr($address, 0, 250) : null;
    }
}
