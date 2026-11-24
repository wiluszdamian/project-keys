<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductMatchAgent;
use App\Ai\Agents\ProductShopsAgent;
use App\Filament\Support\Format;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductShop;
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
 * Szuka tego samego produktu w innych sklepach. Za darmo: mapy stron sklepów z listy (Zakupy → Sklepy),
 * a AI proponuje zapytanie i kolejne sklepy. Opcjonalnie cały internet przez Brave Search API (klucz).
 * Ze znalezionych stron czytamy cenę, AI sprawdza, czy to ten sam model, wariant i zestaw. Pasujące dochodzą do śledzenia.
 */
class ProductFinder
{
    private const MAX_AI_READS = 3;

    public function __construct(
        private ProductReader $reader,
        private ProductTracker $tracker,
        private Notifier $notifier,
        private ListingScorer $scorer,
        private ShopSitemaps $sitemaps,
    ) {}

    public static function isConfigured(): bool
    {
        return self::hasBrave() || ProductReader::aiEnabled() || ProductShop::where('is_active', true)->exists();
    }

    public static function hasBrave(): bool
    {
        return filled(config('products.search.brave_key'));
    }

    /**
     * @return array{candidates: int, added: int, rejected: int, errors: int, offers: list<array{shop: string, price: ?float, same: bool, reason: ?string}>}
     */
    public function find(Product $product, bool $notify = true): array
    {
        if (! self::isConfigured()) {
            throw new RuntimeException('Nie ma gdzie szukać: dodaj sklepy (Zakupy → Sklepy), włącz AI albo dodaj klucz Brave Search API.');
        }

        $product->loadMissing('offers');
        $this->prepare($product);
        $before = $this->tracker->snapshot($product);
        $known = $product->offers->pluck('shop')->all();
        $rejected = Cache::get($this->rejectedKey($product), []);

        $candidates = $this->search($product)
            ->reject(fn (array $result) => in_array($result['shop'], $known, true) || in_array(sha1($result['url']), $rejected, true) || $this->skipped($result['shop']))
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
        $addedShops = [];
        $report = [];

        foreach ($pages as $index => $page) {
            $candidate = $candidates[$index];
            [$same, $reason] = $verdicts[$index] ?? [false, null];
            $report[] = ['shop' => $candidate['shop'], 'url' => $candidate['url'], 'price' => $page->price, 'same' => $same, 'reason' => $reason];

            if ($same && in_array($candidate['shop'], $addedShops, true)) {
                continue;
            }

            if (! $same) {
                $rejected[] = sha1($candidate['url']);

                continue;
            }

            try {
                $added[] = $this->tracker->addOffer($product, $candidate['url'], ProductOffer::SOURCE_SEARCH, $page, $reason)->id;
                $addedShops[] = $candidate['shop'];
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
     * Kandydaci: strony z map sklepów z listy, a z kluczem Brave także wyniki z całego internetu (po EAN i nazwie).
     *
     * @return Collection<int, array{url: string, shop: string, title: string, snippet: string}>
     */
    public function search(Product $product): Collection
    {
        $known = $product->offers->pluck('shop')->all();
        $results = $this->sitemaps->search(ShopSitemaps::queryFor($product), $known, $product->name)
            ->map(fn (array $row) => collect($row)->except('score')->all());

        if (self::hasBrave()) {
            $queries = array_filter([$product->gtin, ShopSitemaps::queryFor($product)]);
            $web = collect($queries)->flatMap(fn (string $query) => $this->brave($query))->unique('shop');
            $results = $results->concat($web);
        }

        return $results->unique('url')->values();
    }

    /**
     * Przed szukaniem: AI ustala krótkie zapytanie i proponuje sklepy (raz na 30 dni na produkt),
     * a mapy nowych i starych sklepów pobieramy od nowa (kilka naraz, reszta co tydzień w tle).
     */
    public function prepare(Product $product): void
    {
        if (ProductReader::aiEnabled() && Cache::add("products:shops-suggested:{$product->id}", true, now()->addDays(30))) {
            try {
                $this->suggestShops($product);
            } catch (Throwable $exception) {
                $this->scorer->countError();
                Log::warning("Monitor cen: AI nie zaproponowało sklepów dla produktu #{$product->id}: {$exception->getMessage()}");
            }
        }

        ProductShop::where('is_active', true)
            ->where(fn ($query) => $query->whereNull('synced_at')->orWhere('synced_at', '<=', now()->subDays((int) config('products.shops.sync_every_days'))))
            ->orderByRaw('synced_at is not null')
            ->orderBy('synced_at')
            ->limit((int) config('products.shops.sync_per_search'))
            ->get()
            ->each(fn (ProductShop $shop) => $this->sitemaps->sync($shop));
    }

    /**
     * AI: zapytanie do szukania (gdy osoba go nie ustawiła) i sklepy, które mogą mieć produkt.
     *
     * @return list<string> domeny dodane do listy sklepów
     */
    public function suggestShops(Product $product): array
    {
        $this->scorer->countCall();
        $data = ProductShopsAgent::make()->prompt(implode("\n", array_filter([
            "Produkt: {$product->name}",
            $product->brand ? "Marka: {$product->brand}" : null,
            $product->note ? 'Notatka osoby: '.Str::squish($product->note) : null,
            'Sklepy, które już znamy: '.(ProductShop::pluck('domain')->merge($product->offers->pluck('shop'))->unique()->implode(', ') ?: 'brak'),
        ])))->toArray();

        if (blank($product->search_query) && is_string($data['query'] ?? null) && trim($data['query']) !== '') {
            $product->forceFill(['search_query' => Str::limit(trim($data['query']), 120, '')])->save();
        }

        $added = [];

        foreach (array_slice($data['shops'] ?? [], 0, 8) as $row) {
            $domain = is_array($row) ? ProductUrl::shop('https://'.preg_replace('#^https?://#i', '', trim((string) ($row['domain'] ?? '')))) : '';

            if (! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) || $this->skipped($domain) || ProductShop::where('domain', $domain)->exists()) {
                continue;
            }

            ProductShop::create(['domain' => $domain, 'name' => Str::limit(trim((string) ($row['name'] ?? '')), 100, '') ?: null, 'source' => 'ai']);
            $added[] = $domain;
        }

        return $added;
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
