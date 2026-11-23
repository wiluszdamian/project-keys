<?php

namespace App\Scrapers;

use App\Enums\DealType;
use App\Enums\PropertyType;
use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\Contracts\EnrichesListings;
use App\Scrapers\Contracts\PortalScraper;
use App\Scrapers\Data\ListingData;
use App\Scrapers\Data\ScrapeResult;
use App\Scrapers\Exceptions\ScraperException;
use App\Scrapers\Support\TextParser;
use App\Services\Geo\SearchArea;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Rzeszowiak.pl, lokalne ogłoszenia z Rzeszowa i okolic (HTML w ISO-8859-2).
 *
 * Osobne kategorie dla osób prywatnych i agencji. Portal nie filtruje miejscowości, metrażu
 * ani pokoi, a lista nie ma tych pól. Miejscowość sprawdzamy w tytule i opisie, metraż i pokoje
 * czytamy z tekstu, a stronę ogłoszenia (pełne dane) pobieramy przed oceną AI.
 */
class RzeszowiakScraper implements EnrichesListings, PortalScraper
{
    // [ścieżka, numer kategorii, czy osoba prywatna]
    private const CATEGORIES = [
        'rent' => [
            ['Nieruchomosci-Mam-do-wynajecia-', 210, true],
            ['Nieruchomosci-Mam-do-wynajecia-agencje--', 260, false],
        ],
        'sale' => [
            ['Nieruchomosci-Sprzedam-', 307, true],
            ['Nieruchomosci-Sprzedam-agencje-', 258, false],
        ],
    ];

    // Ogłoszenia, które nie są całym mieszkaniem ani domem (agencje wrzucają je do tej samej kategorii).
    private const NOT_A_FLAT = '/\b(lokal\w* (uzytkow|uslugow|handlow|biurow)|pokoj\w* (do wynajecia|dla|w mieszkaniu)|wynajme pokoj|stancj|biur(o|a) |garaz|magazyn|hala |dzialk|miejsce parkingowe|miejsce postojowe)/';

    public function __construct(private HttpFetcher $fetcher, private SearchArea $area) {}

    public function name(): string
    {
        return 'rzeszowiak';
    }

    public function buildSearchUrl(Search $search, int $page = 1): string
    {
        return $this->categoryUrl($search, self::CATEGORIES[$search->deal_type->value][0], $page);
    }

    public function fetchListings(Search $search): ScrapeResult
    {
        $listings = [];
        $pages = 0;

        foreach (self::CATEGORIES[$search->deal_type->value] as $category) {
            for ($page = 1; $page <= config('scrapers.max_pages'); $page++) {
                $parsed = $this->parse($this->fetcher->get($this->name(), $this->categoryUrl($search, $category, $page)), $search);
                $pages++;

                foreach ($parsed['listings'] as $listing) {
                    $listings[$listing->externalId] ??= $listing->with(isPrivateOwner: $category[2]);
                }

                if ($parsed['listings'] === [] || $page >= ($parsed['total_pages'] ?? 1)) {
                    break;
                }
            }
        }

        return new ScrapeResult(array_values($listings), $pages);
    }

    /**
     * Strona kategorii. Zwraca tylko ogłoszenia, które w tytule lub opisie wspominają miejscowość z wyszukiwania
     * (dla Rzeszowa także te bez żadnej miejscowości, bo to portal rzeszowski).
     */
    public function parse(string $body, Search $search): array
    {
        $document = $this->document($body);
        $boxes = $document->querySelectorAll('div.promobox, div.normalbox');
        $listings = [];

        if ($boxes->length === 0 && ! str_contains($body, 'Strona')) {
            throw new ScraperException('Rzeszowiak: brak listy ogłoszeń. Strona mogła się zmienić.');
        }

        foreach ($boxes as $box) {
            $listing = $this->toListingData($box, $search);

            if ($listing !== null) {
                $listings[] = $listing;
            }
        }

        preg_match('/Strona\s+\d+\s+z\s+(\d+)/u', $document->body?->textContent ?? '', $pages);

        return ['listings' => $listings, 'total_pages' => isset($pages[1]) ? (int) $pages[1] : 1];
    }

    public function enrich(Listing $listing): bool
    {
        $details = $this->parseOffer($this->fetcher->get($this->name(), $listing->url));

        $listing->description = $details['description'] ?? $listing->description;
        $listing->area = $details['area'] ?? $listing->area;
        $listing->rooms = $details['rooms'] ?? $listing->rooms;
        $listing->floor = $details['floor'] ?? $listing->floor;
        $listing->street = $details['street'] ?? $listing->street;
        $listing->city = $details['city'] ?? $listing->city;
        $listing->rent_extra ??= $this->rentExtra($details['czynsz'], $listing->price, $listing->deal_type);
        $listing->price_per_m2 = $listing->price && $listing->area ? (int) round($listing->price / (float) $listing->area) : null;
        $listing->raw = array_merge($listing->raw ?? [], ['details' => $details['details']]);
        $listing->content_hash = ListingData::hashOf($listing->title, $listing->price, $listing->area, $listing->description);

        return $listing->isDirty() && $listing->save();
    }

    /**
     * Strona ogłoszenia: pary "etykieta : wartość" i pełny opis.
     *
     * @return array{description: ?string, area: ?float, rooms: ?int, floor: ?string, city: ?string, street: ?string, czynsz: ?int, details: array<string, string>}
     */
    public function parseOffer(string $html): array
    {
        $document = $this->document($html);
        $fields = [];

        foreach ($document->querySelectorAll('div.ogloszeniebox-content div.label') as $label) {
            $value = $label->nextElementSibling;

            if ($value?->classList->contains('value')) {
                $fields[trim(rtrim(trim($label->textContent), ':'))] = trim(preg_replace('/\s+/u', ' ', $value->textContent));
            }
        }

        $content = $document->querySelector('div.ogloszeniebox-content div.content');
        [$city, $street] = $this->splitLocation($fields['polożenie'] ?? $fields['położenie'] ?? null);
        $floor = isset($fields['piętro']) ? trim(Str::before($fields['piętro'], '/')) : null;

        return [
            'description' => $content ? $this->clean(TextParser::htmlToText($content->innerHTML)) : null,
            'area' => isset($fields['powierzchnia']) ? TextParser::number($fields['powierzchnia']) : null,
            'rooms' => isset($fields['pokoje']) ? TextParser::int($fields['pokoje']) : null,
            'floor' => match ($floor) {
                null, '' => null,
                '0' => 'parter',
                default => $floor,
            },
            'city' => $city,
            'street' => $street,
            'czynsz' => isset($fields['czynsz']) ? TextParser::int($fields['czynsz']) : null,
            'details' => array_filter([
                'building' => $fields['budynek'] ?? null,
                'condition' => $fields['stan'] ?? null,
                'building_floors' => isset($fields['piętro']) && str_contains($fields['piętro'], '/') ? trim(Str::after($fields['piętro'], '/')) : null,
            ]),
        ];
    }

    private function categoryUrl(Search $search, array $category, int $page): string
    {
        [$path, $number] = $category;
        $type = match (true) {
            $search->property_type === PropertyType::House => $search->deal_type === DealType::Rent ? 'dom' : 'domy',
            default => $search->deal_type === DealType::Rent ? 'mieszkanie' : 'mieszkania',
        };

        // Kod: kategoria (3 cyfry), strona (3), sortowanie 1 = najnowsze, 3 = 35 na stronie, 5 = ostatnie 30 dni.
        $code = sprintf('%03d%03d%d%d%d', $number, $page, 1, 3, 5).'5';

        $query = array_filter([
            'min' => $search->price_min,
            'max' => $search->price_max,
            'r' => $type,
        ], fn ($value) => $value !== null);

        return config('scrapers.rzeszowiak.base_url').'/'.$path.$code.'?'.http_build_query($query);
    }

    private function toListingData(Element $box, Search $search): ?ListingData
    {
        $prefix = $box->classList->contains('normalbox') ? 'normalbox' : 'promobox';
        $link = $box->querySelector("div.{$prefix}-title-left a");

        if (! $link || ! preg_match('/-(\d+)$/', $href = $link->getAttribute('href') ?? '', $id)) {
            return null;
        }

        $title = trim(preg_replace('/^\d+\.\s*/u', '', str_replace("\u{00A0}", ' ', $link->textContent)));
        $priceText = $box->querySelector("div.{$prefix}-title-left2")?->textContent ?? '';
        $description = $this->clean(TextParser::htmlToText($box->querySelector("div.{$prefix}-body-right")?->innerHTML ?? ''));
        $text = $title.' '.$description;

        $place = preg_match(self::NOT_A_FLAT, TextParser::normalize($title)) ? null : $this->place($text, $search);

        if ($place === null) {
            return null;
        }

        $price = preg_match('/cena:\s*([\d\s]+)/u', $priceText, $match) ? TextParser::int($match[1]) : null;
        $perM2 = preg_match('/\[\s*([\d\s]+)\s*\/\s*m2/u', $priceText, $match) ? TextParser::int($match[1]) : null;
        $area = $price && $perM2 ? round($price / $perM2, 2) : TextParser::area($text);
        $image = $box->querySelector("div.{$prefix}-body-left img")?->getAttribute('src');
        $added = $box->querySelector('span.dodane b')?->textContent;

        return new ListingData(
            portal: $this->name(),
            externalId: $id[1],
            url: config('scrapers.rzeszowiak.base_url').$href,
            title: $title,
            dealType: $search->deal_type,
            price: $price,
            area: $area,
            rooms: TextParser::rooms($title) ?? TextParser::rooms($description),
            city: $place,
            description: $description ?: null,
            imageUrl: $image ? config('scrapers.rzeszowiak.base_url').$image : null,
            raw: array_filter(['id' => $id[1], 'added' => $this->addedAt($added), 'price_per_m2' => $perM2]),
        );
    }

    /**
     * Miejscowość oferty, gdy jest w obszarze wyszukiwania, inaczej null.
     * Najpierw miejscowość z wyszukiwania w tytule lub opisie. Ogłoszenie bez żadnej znanej nazwy uznajemy za rzeszowskie.
     * Przy wyszukiwaniu z promieniem pasują też znane miejscowości w promieniu.
     */
    private function place(string $text, Search $search): ?string
    {
        if (TextParser::mentionsPlace($text, $search->city)) {
            return $search->city;
        }

        $mentioned = collect(config('scrapers.rzeszowiak.other_places'))
            ->filter(fn (string $place) => TextParser::mentionsPlace($text, $place))
            ->values();

        if ($mentioned->isEmpty()) {
            return TextParser::normalize($search->city) === config('scrapers.rzeszowiak.home_city')
                ? $search->city
                : ($search->radius_km && $this->area->containsPlace($search, config('scrapers.rzeszowiak.home_city_name')) ? config('scrapers.rzeszowiak.home_city_name') : null);
        }

        return $search->radius_km ? $mentioned->first(fn (string $place) => $this->area->containsPlace($search, $place)) : null;
    }

    /**
     * "Rzeszów. Dąbrowskiego 85", "Rzeszów ul. Architektów 11" -> [miasto, ulica].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitLocation(?string $location): array
    {
        if (! $location) {
            return [null, null];
        }

        if (preg_match('/^([^.,]+?)(?:[.,]\s*|\s+(?=ul\.|al\.|os\.))(.+)$/u', $location, $match)) {
            return [trim($match[1]), trim($match[2]) ?: null];
        }

        return [trim($location), null];
    }

    /**
     * Przy wynajmie pole "czynsz" to zwykle sam najem. Czynsz administracyjny tylko, gdy jest wyraźnie mniejszy od ceny.
     */
    private function rentExtra(?int $czynsz, ?int $price, DealType $dealType): ?int
    {
        if ($czynsz === null || $czynsz === 0) {
            return null;
        }

        if ($dealType === DealType::Sale) {
            return $czynsz < 5000 ? $czynsz : null;
        }

        return $price !== null && $czynsz < $price * 0.6 ? $czynsz : null;
    }

    private function addedAt(?string $text): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        return match (true) {
            preg_match('/^dziś,\s*(\d{1,2}:\d{2})/u', $text, $match) === 1 => now()->format('Y-m-d').' '.$match[1],
            preg_match('/^\d{4}-\d{2}-\d{2} \d{1,2}:\d{2}/', $text, $match) === 1 => Carbon::parse($match[0])->format('Y-m-d H:i'),
            default => null,
        };
    }

    /**
     * Portal podaje ISO-8859-2. Opisy agencji mają czasem zepsute znaki z UTF-8 ("mÂ˛").
     */
    private function document(string $body): HTMLDocument
    {
        if (! mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-2');
        }

        return HTMLDocument::createFromString($body, LIBXML_NOERROR, 'UTF-8');
    }

    private function clean(string $text): string
    {
        return trim(preg_replace(['/mÂ˛/u', '/â[\x{0080}-\x{009F}]{2}/u'], ['m²', '-'], $text));
    }
}
