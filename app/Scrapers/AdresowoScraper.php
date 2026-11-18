<?php

namespace App\Scrapers;

use App\Enums\DealType;
use App\Enums\PropertyType;
use App\Models\Listing;
use App\Models\Search;
use App\Scrapers\Concerns\FetchesPages;
use App\Scrapers\Concerns\ResolvesLocation;
use App\Scrapers\Concerns\StoresCoordinates;
use App\Scrapers\Contracts\EnrichesListings;
use App\Scrapers\Contracts\PortalScraper;
use App\Scrapers\Data\ListingData;
use App\Scrapers\Exceptions\ScraperException;
use App\Scrapers\Support\TextParser;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Adresowo, głównie oferty bez pośredników. Zwykły HTML. W adresie da się filtrować tylko pokoje
 * (/f/.../fp3p4p5p6), cenę i metraż sprawdza ListingMatcher. Kolejność to trafność, nie data,
 * więc czytamy kolejne strony (40 ofert na pierwszej, potem po 120).
 */
class AdresowoScraper implements EnrichesListings, PortalScraper
{
    use FetchesPages, ResolvesLocation, StoresCoordinates;

    public function __construct(private HttpFetcher $fetcher) {}

    public function name(): string
    {
        return 'adresowo';
    }

    public function buildSearchUrl(Search $search, int $page = 1): string
    {
        $type = ($search->property_type === PropertyType::House ? 'domy' : 'mieszkania')
            .($search->deal_type === DealType::Rent ? '-wynajem' : '');
        $location = $this->location($search->city);
        $suffix = $page > 1 ? "_l{$page}" : '';
        $base = config('scrapers.adresowo.base_url');

        if ($search->rooms_min !== null || $search->rooms_max !== null) {
            $rooms = range(min(6, $search->rooms_min ?? 1), min(6, $search->rooms_max ?? 6));

            return "{$base}/f/{$type}/{$location}/fp".implode('p', $rooms).$suffix;
        }

        return "{$base}/{$type}/{$location}/{$suffix}";
    }

    public function parse(string $body, Search $search): array
    {
        if (! preg_match('/re\.totalOffers\s*=\s*(\d+)/', $body, $total)) {
            throw new ScraperException('Adresowo: brak liczby ofert na stronie. Strona mogła się zmienić.');
        }

        // Tylko wyniki. Za listą są "oferty z najbliższej okolicy" z innych miejscowości.
        $start = strpos($body, 'id="offer-list-results"');
        $results = $start === false ? '' : substr($body, $start, (strpos($body, '</section>', $start) ?: strlen($body)) - $start);
        $document = HTMLDocument::createFromString('<!doctype html><html><body><div '.$results.'</body></html>', LIBXML_NOERROR);
        $listings = [];

        foreach ($document->querySelectorAll('div[data-offer-card][data-id]') as $card) {
            $listing = $this->toListingData($card, $search);

            if ($listing !== null) {
                $listings[] = $listing;
            }
        }

        $offers = (int) $total[1];

        return ['listings' => $listings, 'total_pages' => $offers <= 40 ? 1 : 1 + (int) ceil(($offers - 40) / 120)];
    }

    public function enrich(Listing $listing): bool
    {
        $html = $this->fetcher->get($this->name(), $listing->url);
        $details = $this->parseOffer($html);
        $this->applyCoordinates($listing, TextParser::coordinates($html));

        $listing->description = $details['description'] ?? $listing->description;
        $listing->floor ??= $details['floor'];
        $listing->is_private_owner ??= $details['is_private_owner'];
        $listing->raw = array_merge($listing->raw ?? [], ['details' => $details['details']]);
        $listing->content_hash = ListingData::hashOf($listing->title, $listing->price, $listing->area, $listing->description);

        return $listing->isDirty() && $listing->save();
    }

    /**
     * Pełny opis i piętro z ld+json (Place), typ ogłoszeniodawcy ze skryptu. Czynsz jest tylko w opisie.
     *
     * @return array{description: ?string, floor: ?string, is_private_owner: ?bool, details: array<string, mixed>}
     */
    public function parseOffer(string $html): array
    {
        $place = [];
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $scripts);

        foreach ($scripts[1] as $script) {
            foreach (json_decode($script, true)['@graph'] ?? [] as $node) {
                if (($node['@type'] ?? null) === 'Place') {
                    $place = $node;
                }
            }
        }

        // "Rzeszów Drabinianka, ul. Strażacka Mieszkanie - 3 pokoje - 77 m² - 3 piętro"
        $lastPart = trim((string) last(explode(' - ', $place['name'] ?? '')));
        $floor = match (true) {
            str_contains($lastPart, 'parter') => 'parter',
            preg_match('/^(\d+)\s*piętro/u', $lastPart, $match) === 1 => $match[1],
            default => null,
        };
        $phoneType = preg_match("/re\.offerPhoneType\s*=\s*'([^']+)'/", $html, $match) ? $match[1] : null;

        return [
            'description' => isset($place['description']) ? trim($place['description']) : null,
            'floor' => $floor,
            'is_private_owner' => match ($phoneType) {
                'private' => true,
                null => null,
                default => false,
            },
            'details' => array_filter([
                'address' => $place['address']['streetAddress'] ?? null,
            ]),
        ];
    }

    private function toListingData(Element $card, Search $search): ?ListingData
    {
        $link = $card->querySelector('a[data-track="offer-link"]');
        $href = $link?->getAttribute('href');

        if (! $href) {
            return null;
        }

        $values = [];

        foreach ($card->querySelectorAll('p.flex-auto') as $box) {
            $unit = trim($box->querySelector('span.text-xs')?->textContent ?? '');
            $value = $box->querySelector('span.font-bold')?->textContent;

            if ($value !== null && $unit !== '') {
                $values[$unit] = $value;
            }
        }

        $lines = $link->querySelectorAll('span.line-clamp-1');
        $place = trim(str_replace("\u{00A0}", ' ', $lines->item(0)?->textContent ?? ''));
        $street = trim(str_replace("\u{00A0}", ' ', $lines->item(1)?->textContent ?? ''));
        $district = trim(preg_replace('/^'.preg_quote($search->city, '/').'/u', '', $place));
        $owner = trim($link->querySelector('span.shrink-0')?->textContent ?? '');
        $image = $card->querySelector('img');
        $description = $card->querySelector('p.line-clamp-4')?->textContent;

        return new ListingData(
            portal: $this->name(),
            externalId: $card->getAttribute('data-id'),
            url: config('scrapers.adresowo.base_url').$href,
            title: trim($image?->getAttribute('alt') ?: $place),
            dealType: $search->deal_type,
            price: isset($values['zł']) ? TextParser::int($values['zł']) : null,
            area: isset($values['m²']) ? TextParser::number($values['m²']) : null,
            rooms: isset($values['pok.']) ? TextParser::int($values['pok.']) : null,
            city: str_starts_with($place, $search->city) ? $search->city : (explode(' ', $place)[0] ?: $search->city),
            district: $district !== '' && $district !== $place ? $district : null,
            street: $street !== '' ? $street : null,
            isPrivateOwner: match ($owner) {
                'bez pośredników' => true,
                '' => null,
                default => false,
            },
            description: $description ? trim(preg_replace('/\s*\.\.\.\s*$/u', '...', trim($description))) : null,
            imageUrl: $image?->getAttribute('src'),
            raw: ['id' => $card->getAttribute('data-id')],
        );
    }
}
