<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductMatchAgent;
use App\Filament\Support\Format;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Notifications\Notifier;
use App\Services\ListingScorer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Szuka tego samego produktu w innych sklepach: wyszukiwarka (Brave Search API), odczyt cen ze stron
 * i sprawdzenie przez AI, czy to ten sam model, wariant i zestaw. Pasujące oferty dochodzą do śledzenia.
 */
class ProductFinder
{
    private const MAX_AI_READS = 3;

    public function __construct(
        private ProductReader $reader,
        private ProductTracker $tracker,
        private Notifier $notifier,
        private ListingScorer $scorer,
    ) {}

    public static function isConfigured(): bool
    {
        return filled(config('products.search.brave_key'));
    }

    /**
     * @return array{candidates: int, added: int, rejected: int, errors: int, offers: list<array{shop: string, price: ?float, same: bool, reason: ?string}>}
     */
    public function find(Product $product, bool $notify = true): array
    {
        if (! self::isConfigured()) {
            throw new RuntimeException('Brak klucza Brave Search API (Ustawienia → Produkty).');
        }

        $product->loadMissing('offers');
        $before = $this->tracker->snapshot($product);
        $known = $product->offers->pluck('shop')->all();
        $rejected = Cache::get($this->rejectedKey($product), []);

        $candidates = $this->search($product)
            ->reject(fn (array $result) => in_array($result['shop'], $known, true) || in_array(sha1($result['url']), $rejected, true) || $this->skipped($result['shop']))
            ->unique('shop')
            ->take((int) config('products.search.max_candidates'))
            ->values();

        $pages = [];
        $errors = 0;
        $aiReads = 0;

        foreach ($candidates as $index => $candidate) {
            try {
                $page = $this->reader->read($candidate['url'], useAi: $aiReads < self::MAX_AI_READS);
                $aiReads += $page->method === 'ai' ? 1 : 0;
                $pages[$index] = $page;
            } catch (Throwable $exception) {
                $errors++;
                Log::info("Monitor cen: nie odczytałem {$candidate['url']}: {$exception->getMessage()}");
            }
        }

        $verdicts = $this->match($product, $candidates, $pages);
        $added = [];
        $report = [];

        foreach ($pages as $index => $page) {
            $candidate = $candidates[$index];
            [$same, $reason] = $verdicts[$index] ?? [false, null];
            $report[] = ['shop' => $candidate['shop'], 'price' => $page->price, 'same' => $same, 'reason' => $reason];

            if (! $same) {
                $rejected[] = sha1($candidate['url']);

                continue;
            }

            try {
                $added[] = $this->tracker->addOffer($product, $candidate['url'], ProductOffer::SOURCE_SEARCH, $page, $reason)->id;
            } catch (Throwable $exception) {
                Log::info("Monitor cen: nie dodałem {$candidate['url']}: {$exception->getMessage()}");
            }
        }

        Cache::put($this->rejectedKey($product), array_values(array_unique($rejected)), now()->addDays(30));
        $product->forceFill(['offers_searched_at' => now()])->save();

        $events = $this->tracker->refresh($product, $before);
        $primary = $product->primaryOffer();
        $cheaper = $product->offers
            ->whereIn('id', $added)
            ->filter(fn (ProductOffer $offer) => $offer->isBuyable() && $primary?->price && $offer->price < $primary->price)
            ->sortBy('price')
            ->first();

        if ($cheaper) {
            $events[] = ['type' => 'cheaper_elsewhere', 'text' => "Taniej w {$cheaper->shop}: ".Format::pln($cheaper->price).' (o '.Format::pln($primary->price - $cheaper->price)." mniej niż w {$primary->shop})."];
        }

        if ($notify && $events !== []) {
            $this->notifier->productEvents($product, $events);
        }

        return [
            'candidates' => $candidates->count(),
            'added' => count($added),
            'rejected' => count($pages) - count($added),
            'errors' => $errors,
            'offers' => $report,
        ];
    }

    /**
     * Wyniki wyszukiwania: po kodzie EAN (gdy jest) i po nazwie.
     *
     * @return Collection<int, array{url: string, shop: string, title: string, snippet: string}>
     */
    public function search(Product $product): Collection
    {
        $queries = array_filter([
            $product->gtin,
            Str::words(Str::squish(preg_replace('/[|–—].*$/u', '', $product->name)), 14, ''),
        ]);

        return collect($queries)
            ->flatMap(fn (string $query) => $this->brave($query))
            ->unique('url')
            ->values();
    }

    /**
     * @return list<array{url: string, shop: string, title: string, snippet: string}>
     */
    private function brave(string $query): array
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'X-Subscription-Token' => config('products.search.brave_key'),
        ])->timeout(20)->get('https://api.search.brave.com/res/v1/web/search', [
            'q' => $query,
            'country' => 'PL',
            'search_lang' => 'pl',
            'count' => 20,
            'safesearch' => 'moderate',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("Brave Search odpowiedział {$response->status()}: ".mb_substr($response->body(), 0, 200));
        }

        return collect($response->json('web.results', []))
            ->filter(fn ($result) => is_array($result) && is_string($result['url'] ?? null) && preg_match('#^https?://#', $result['url']))
            ->map(fn (array $result) => [
                'url' => $result['url'],
                'shop' => ProductUrl::shop($result['url']),
                'title' => strip_tags((string) ($result['title'] ?? '')),
                'snippet' => Str::limit(strip_tags((string) ($result['description'] ?? '')), 300),
            ])
            ->values()
            ->all();
    }

    /**
     * Czy oferty to ten sam produkt: AI, a bez AI zgodny kod EAN albo prawie ta sama nazwa.
     *
     * @param  Collection<int, array{url: string, shop: string, title: string, snippet: string}>  $candidates
     * @param  array<int, ProductPage>  $pages
     * @return array<int, array{0: bool, 1: ?string}>
     */
    private function match(Product $product, Collection $candidates, array $pages): array
    {
        if ($pages === []) {
            return [];
        }

        if (ProductReader::aiEnabled()) {
            try {
                return $this->matchWithAi($product, $candidates, $pages);
            } catch (Throwable $exception) {
                $this->scorer->countError();
                Log::warning("Monitor cen: AI nie porównało ofert produktu #{$product->id}: {$exception->getMessage()}");
            }
        }

        $verdicts = [];

        foreach ($pages as $index => $page) {
            if ($product->gtin && $page->gtin) {
                $verdicts[$index] = [$product->gtin === $page->gtin, $product->gtin === $page->gtin ? 'Ten sam kod EAN.' : 'Inny kod EAN.'];

                continue;
            }

            $similarity = self::similarity($product->name, (string) ($page->name ?? $candidates[$index]['title']));
            $verdicts[$index] = [$similarity >= 0.85, 'Podobieństwo nazwy '.round($similarity * 100).'% (bez AI).'];
        }

        return $verdicts;
    }

    /**
     * @param  Collection<int, array{url: string, shop: string, title: string, snippet: string}>  $candidates
     * @param  array<int, ProductPage>  $pages
     * @return array<int, array{0: bool, 1: ?string}>
     */
    private function matchWithAi(Product $product, Collection $candidates, array $pages): array
    {
        $primary = $product->primaryOffer();
        $lines = [
            '## Produkt, który osoba chce kupić',
            "- nazwa: {$product->name}",
            $primary?->title && $primary->title !== $product->name ? "- nazwa w sklepie {$primary->shop}: {$primary->title}" : null,
            $product->brand ? "- marka: {$product->brand}" : null,
            $product->gtin ? "- EAN: {$product->gtin}" : null,
            $product->mpn ? "- kod producenta: {$product->mpn}" : null,
            $product->best_price ? '- cena: '.Format::pln($product->best_price) : null,
            $product->note ? '- notatka osoby: '.Str::squish($product->note) : null,
            '',
            '## Oferty z innych sklepów',
        ];

        foreach ($pages as $index => $page) {
            $candidate = $candidates[$index];
            $lines[] = implode("\n", array_filter([
                "### Oferta {$index}",
                "- sklep: {$candidate['shop']}",
                '- nazwa: '.($page->name ?? $candidate['title']),
                $page->brand ? "- marka: {$page->brand}" : null,
                $page->gtin ? "- EAN: {$page->gtin}" : null,
                $page->mpn ? "- kod producenta: {$page->mpn}" : null,
                '- cena: '.Format::pln($page->price),
                $candidate['snippet'] ? "- opis z wyszukiwarki: {$candidate['snippet']}" : null,
            ]));
        }

        $this->scorer->countCall();
        $data = ProductMatchAgent::make()->prompt(implode("\n", array_filter($lines, fn ($line) => $line !== null)))->toArray();
        $verdicts = [];

        foreach ($data['matches'] ?? [] as $row) {
            if (is_array($row) && isset($row['index']) && array_key_exists((int) $row['index'], $pages)) {
                $verdicts[(int) $row['index']] = [(bool) ($row['same'] ?? false), Str::limit(trim((string) ($row['reason'] ?? '')), 480) ?: null];
            }
        }

        return $verdicts;
    }

    /**
     * Podobieństwo nazw (wspólne słowa), 0 do 1.
     */
    public static function similarity(string $a, string $b): float
    {
        $words = fn (string $text) => collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)))->filter(fn ($word) => mb_strlen($word) > 1)->unique();
        [$left, $right] = [$words($a), $words($b)];

        if ($left->isEmpty() || $right->isEmpty()) {
            return 0.0;
        }

        return $left->intersect($right)->count() / max($left->count(), $right->count());
    }

    private function skipped(string $shop): bool
    {
        foreach (config('products.search.skip_domains') as $domain) {
            if ($shop === $domain || str_ends_with($shop, ".{$domain}")) {
                return true;
            }
        }

        return false;
    }

    private function rejectedKey(Product $product): string
    {
        return "products:rejected:{$product->id}";
    }
}
