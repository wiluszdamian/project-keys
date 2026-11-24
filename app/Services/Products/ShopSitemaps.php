<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductShop;
use App\Models\ProductShopUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Darmowe szukanie produktu w sklepach z listy: pobieramy mapę strony sklepu (sitemap, publikowaną dla robotów)
 * i szukamy adresów, w których są słowa z nazwy produktu. Wyszukiwarek sklepów nie używamy,
 * bo większość sklepów zabrania tego w robots.txt.
 */
class ShopSitemaps
{
    /**
     * Słowa, które nic nie mówią o konkretnym produkcie.
     */
    private const STOPWORDS = [
        'do', 'dla', 'ze', 'na', 'od', 'po', 'za', 'oraz', 'lub', 'the', 'and', 'with', 'for', 'nowy', 'nowa', 'nowe',
        'wozek', 'wozki', 'zestaw', 'komplet', 'sklep', 'cena', 'promocja', 'produkt', 'oryginalny', 'pl',
    ];

    /**
     * Mapy podrzędne z produktami i te, które pomijamy (kategorie, blog, zdjęcia).
     */
    private const PRODUCT_SITEMAP = '/product|produkt|towar|item|offer|oferta|_p_|-p-/i';

    private const SKIP_SITEMAP = '/categor|kategor|cms|blog|image|img|photo|video|page|tag|brand|producer|producent|manufacturer|news|article|artyku|static|landing|author/i';

    /**
     * Pobiera mapę strony sklepu i zapisuje adresy stron.
     *
     * @return int liczba adresów
     */
    public function sync(ProductShop $shop): int
    {
        $base = 'https://'.$shop->domain;

        if (! ProductUrl::isPublic($base)) {
            return $this->failed($shop, 'error', 'Ten adres nie prowadzi do publicznej strony.');
        }

        $robots = RobotsTxt::for($base);
        $roots = $shop->sitemap_url ? [$shop->sitemap_url] : ($robots->sitemaps ?: ["{$base}/sitemap.xml", "{$base}/sitemap_index.xml"]);
        $roots = $this->preferPolish($roots);
        $urls = [];
        $visited = 0;
        $errors = [];

        try {
            $this->walk($roots, $shop, $urls, $visited, $errors, depth: 0);
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        $urls = array_values(array_filter(array_unique($urls), fn (string $url) => $robots->allows($url)));

        if ($urls === []) {
            return $this->failed($shop, str_contains(implode(' ', $errors), '403') ? 'blocked' : ($errors ? 'error' : 'empty'), $errors ? Str::limit(implode(' ', array_unique($errors)), 500) : 'Mapa strony nie ma adresów produktów.');
        }

        DB::transaction(function () use ($shop, $urls) {
            $shop->urls()->delete();

            foreach (array_chunk($urls, 1000) as $chunk) {
                ProductShopUrl::insert(array_map(fn (string $url) => ['product_shop_id' => $shop->id, 'url' => $url, 'slug' => self::slug($url)], $chunk));
            }
        });

        $shop->forceFill(['status' => 'ok', 'last_error' => null, 'urls_count' => count($urls), 'synced_at' => now()])->save();

        return count($urls);
    }

    /**
     * Strony produktu w sklepach z listy, najlepiej pasujące do zapytania.
     *
     * @param  list<string>  $skipShops  sklepy, które już śledzimy
     * @param  string  $context  pełna nazwa produktu: zgodne słowa (kolor, zestaw) podnoszą wynik
     * @return Collection<int, array{url: string, shop: string, title: string, snippet: string, score: float}>
     */
    public function search(string $query, array $skipShops = [], string $context = ''): Collection
    {
        $tokens = self::tokens($query);
        $extra = array_values(array_diff(self::tokens($context), $tokens));
        $required = array_values(array_filter($tokens, fn (string $token) => ! preg_match('/^(19|20)\d\d$/', $token)));

        if (count($required) < 1) {
            return collect();
        }

        // Dwa najdłuższe słowa zawężają wyszukiwanie w bazie, resztę liczymy w PHP.
        $selective = collect($required)->sortByDesc(fn (string $token) => strlen($token))->take(2)->values();
        $perShop = (int) config('products.shops.candidates_per_shop');

        return ProductShopUrl::query()
            ->whereHas('shop', fn ($query) => $query->where('is_active', true))
            ->with('shop:id,domain')
            ->where(function ($query) use ($selective) {
                foreach ($selective as $token) {
                    $query->where('slug', 'ilike', "%{$token}%");
                }
            })
            ->limit(2000)
            ->get()
            ->map(function (ProductShopUrl $row) use ($tokens, $required, $extra) {
                $words = array_flip(explode(' ', $row->slug));
                $matched = count(array_filter($required, fn (string $token) => isset($words[$token])));
                $bonus = count(array_filter(array_diff($tokens, $required), fn (string $token) => isset($words[$token]))) * 0.05
                    + count(array_filter($extra, fn (string $token) => isset($words[$token]))) * 0.02;

                return [
                    'url' => $row->url,
                    'shop' => ProductUrl::shop($row->url),
                    'title' => $row->slug,
                    'snippet' => '',
                    'score' => $matched / count($required) + $bonus,
                    'matched' => $matched,
                    'length' => strlen($row->slug),
                ];
            })
            ->filter(fn (array $row) => $row['score'] >= 0.75 && $row['matched'] >= min(2, count($required)) && ! in_array($row['shop'], $skipShops, true))
            ->sortBy([['score', 'desc'], ['length', 'asc']])
            ->groupBy('shop')
            ->flatMap(fn (Collection $rows) => $rows->take($perShop))
            ->sortBy([['score', 'desc'], ['length', 'asc']])
            ->map(fn (array $row) => collect($row)->only(['url', 'shop', 'title', 'snippet', 'score'])->all())
            ->values();
    }

    /**
     * Krótkie zapytanie z nazwy: bez nawiasów i tego, co po myślniku albo kresce, najwyżej 5 słów.
     */
    public static function queryFor(Product $product): string
    {
        if (filled($product->search_query)) {
            return $product->search_query;
        }

        $name = preg_replace(['/\([^)]*\)/u', '/\s[|–—-]\s.*$/u'], ' ', $product->name);

        return Str::words(Str::squish((string) $name), 5, '');
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        return collect(explode(' ', self::words($text)))
            ->filter(fn (string $word) => strlen($word) >= 2 && ! in_array($word, self::STOPWORDS, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Słowa z adresu: ścieżka i parametry, małe litery bez polskich znaków.
     */
    public static function slug(string $url): string
    {
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH)).' '.rawurldecode((string) parse_url($url, PHP_URL_QUERY));

        return self::words(preg_replace('/\.(html?|php|aspx?)\b/i', ' ', $path));
    }

    private static function words(string $text): string
    {
        return Str::squish(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($text))));
    }

    /**
     * @param  list<string>  $sitemaps
     * @param  list<string>  $urls
     * @param  list<string>  $errors
     */
    private function walk(array $sitemaps, ProductShop $shop, array &$urls, int &$visited, array &$errors, int $depth): void
    {
        $maxUrls = (int) config('products.shops.max_urls');

        foreach ($sitemaps as $sitemap) {
            if ($visited >= (int) config('products.shops.max_sitemaps') || count($urls) >= $maxUrls) {
                return;
            }

            $visited++;

            try {
                $xml = $this->fetch($sitemap);
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();

                continue;
            }

            $locs = $this->locs($xml);

            if (str_contains($xml, '<sitemapindex') && $depth < 2) {
                $this->walk($this->pickChildren($locs), $shop, $urls, $visited, $errors, $depth + 1);

                continue;
            }

            foreach ($locs as $loc) {
                if (ProductUrl::shop($loc) === ProductUrl::shop('https://'.$shop->domain) && count($urls) < $maxUrls) {
                    $urls[] = $loc;
                }
            }
        }
    }

    private function fetch(string $url): string
    {
        $response = Http::withHeaders(['User-Agent' => config('scrapers.user_agent'), 'Accept' => 'application/xml,text/xml,*/*'])
            ->withOptions(['version' => 2.0])
            ->timeout(60)
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Mapa strony {$url} odpowiedziała {$response->status()}.");
        }

        $body = $response->body();

        // Mapy .xml.gz: serwer nie zawsze ustawia nagłówek kompresji.
        if (str_starts_with($body, "\x1f\x8b")) {
            $body = (string) @gzdecode($body);
        }

        return $body;
    }

    /**
     * @return list<string>
     */
    private function locs(string $xml): array
    {
        preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?\s*(.*?)\s*(?:\]\]>)?\s*</loc>#si', $xml, $matches);

        return array_values(array_filter(array_map(fn (string $loc) => html_entity_decode($loc, ENT_QUOTES | ENT_XML1), $matches[1]), fn (string $loc) => preg_match('#^https?://#i', $loc) === 1));
    }

    /**
     * Z indeksu map: najpierw mapy produktów, bez kategorii, bloga i zdjęć; polskie wersje, gdy są.
     *
     * @param  list<string>  $children
     * @return list<string>
     */
    private function pickChildren(array $children): array
    {
        $children = $this->preferPolish($children);
        $products = array_values(array_filter($children, fn (string $url) => preg_match(self::PRODUCT_SITEMAP, $url) === 1));

        return $products ?: array_values(array_filter($children, fn (string $url) => preg_match(self::SKIP_SITEMAP, $url) !== 1));
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function preferPolish(array $urls): array
    {
        $polish = array_values(array_filter($urls, fn (string $url) => preg_match('#/pl[/_-]|[_-]pl[_./-]|pl_pl#i', $url) === 1));

        return $polish ?: $urls;
    }

    private function failed(ProductShop $shop, string $status, string $message): int
    {
        $shop->forceFill(['status' => $status, 'last_error' => $message, 'synced_at' => now()])->save();

        return 0;
    }
}
