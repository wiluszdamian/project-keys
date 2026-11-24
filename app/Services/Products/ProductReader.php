<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductPageAgent;
use App\Scrapers\Exceptions\PortalBlockedException;
use App\Scrapers\HttpFetcher;
use App\Services\ListingScorer;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Pobiera stronę produktu i odczytuje cenę. Gdy sklep nie podaje ceny w danych strony, pyta AI.
 */
class ProductReader
{
    public function __construct(
        private HttpFetcher $http,
        private PageExtractor $extractor,
        private ListingScorer $scorer,
    ) {}

    public static function aiEnabled(): bool
    {
        return config('products.ai') && ListingScorer::isEnabled();
    }

    /**
     * @throws RuntimeException gdy strony nie ma albo nie da się odczytać ceny
     */
    public function read(string $url, bool $useAi = true): ProductPage
    {
        if (! ProductUrl::isPublic($url)) {
            throw new RuntimeException('Ten adres nie prowadzi do publicznej strony.');
        }

        try {
            $response = $this->http->probe('shop:'.ProductUrl::shop($url), $url);
        } catch (PortalBlockedException) {
            throw new RuntimeException('Sklep blokuje automatyczne sprawdzanie (403 albo 429). Następna próba za '.config('scrapers.block_hours').' h.');
        }

        if (in_array($response['status'], [404, 410], true)) {
            throw new ProductGoneException("Sklep odpowiedział {$response['status']}: strony produktu już nie ma.");
        }

        if ($response['status'] >= 400) {
            throw new RuntimeException("Sklep odpowiedział {$response['status']}.");
        }

        $page = $this->extractor->extract($response['body'], $url);

        if (! $page->hasPrice() && $useAi && self::aiEnabled()) {
            $page = $this->readWithAi($response['body'], $url)->fillFrom($page);
        }

        if (! $page->hasPrice()) {
            throw new RuntimeException('Nie znalazłem ceny na stronie'.(self::aiEnabled() ? ', także AI jej nie odczytało.' : '. Włącz AI, żeby odczytywało cenę z treści strony.'));
        }

        return $page;
    }

    private function readWithAi(string $html, string $url): ProductPage
    {
        $text = $this->extractor->visibleText($html, (int) config('products.ai_page_chars'));

        if (mb_strlen($text) < 50) {
            return new ProductPage;
        }

        $this->scorer->countCall();

        try {
            $data = ProductPageAgent::make()->prompt("Adres: {$url}\n\nTekst strony:\n{$text}")->toArray();
        } catch (Throwable $exception) {
            $this->scorer->countError();
            Log::warning("AI nie odczytało ceny z {$url}: {$exception->getMessage()}");

            return new ProductPage;
        }

        if (! ($data['found'] ?? false)) {
            return new ProductPage(name: is_string($data['name'] ?? null) ? $data['name'] : null);
        }

        return new ProductPage(
            name: is_string($data['name'] ?? null) ? $data['name'] : null,
            price: PriceParser::parse($data['price'] ?? null),
            currency: is_string($data['currency'] ?? null) && preg_match('/^[A-Z]{3}$/', strtoupper($data['currency'])) ? strtoupper($data['currency']) : 'PLN',
            available: is_bool($data['available'] ?? null) ? $data['available'] : null,
            regularPrice: PriceParser::parse($data['regular_price'] ?? null),
            lowest30dPrice: PriceParser::parse($data['lowest_30d_price'] ?? null),
            method: 'ai',
        );
    }
}
