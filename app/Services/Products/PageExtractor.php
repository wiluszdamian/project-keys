<?php

namespace App\Services\Products;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * Cena, nazwa i dostępność ze strony produktu, bez AI. Kolejno:
 * dane strukturalne (JSON-LD), meta tagi (Open Graph), microdata, znane układy sklepów,
 * a na końcu pierwsza cena w treści pod nazwą produktu.
 */
class PageExtractor
{
    /**
     * Ceny w znanych sklepach: IdoSell, PrestaShop, Shoper, WooCommerce, Magento, Shopify.
     */
    private const PRICE_SELECTORS = [
        "//*[@id='projector_price_value']",
        "//*[contains(concat(' ', normalize-space(@class), ' '), ' current-price-value ')]/@content",
        "//*[contains(concat(' ', normalize-space(@class), ' '), ' current-price ')]//*[@itemprop='price']/@content",
        "//*[@data-price-type='finalPrice']/@data-price-amount",
        "//em[contains(concat(' ', normalize-space(@class), ' '), ' main-price ')]",
        "//*[contains(concat(' ', normalize-space(@class), ' '), ' summary ')]//p[contains(@class, 'price')]//ins//*[contains(@class, 'woocommerce-Price-amount')]",
        "//*[contains(concat(' ', normalize-space(@class), ' '), ' summary ')]//p[contains(@class, 'price')]//*[contains(@class, 'woocommerce-Price-amount')]",
        "//*[contains(concat(' ', normalize-space(@class), ' '), ' product__price ')]",
        "//*[@id='price_value' or @id='our_price_display' or @id='product-price' or @id='productPrice']",
    ];

    private const PRICE_IN_TEXT = '/(?<![\d.,])(\d{1,3}(?:[ \x{00A0}\x{202F}.]\d{3})+(?:,\d{1,2})?|\d+(?:[.,]\d{1,2})?)\s?(?:zł|PLN)(?![a-ząćęłńóśźż])/ui';

    public function extract(string $html, string $url): ProductPage
    {
        $xpath = $this->xpath($html);
        $page = $this->fromJsonLd($xpath, $url);

        foreach (['meta' => $this->fromMeta(...), 'microdata' => $this->fromMicrodata(...), 'shop' => $this->fromShopLayout(...), 'text' => $this->fromText(...)] as $method => $reader) {
            if ($page->hasPrice()) {
                break;
            }

            $other = $reader($xpath);

            if ($other->hasPrice()) {
                $other->method = $method;
                $page = $other->fillFrom($page);
            } else {
                $page->fillFrom($other);
            }
        }

        $page->name ??= $this->metaContent($xpath, ['og:title', 'twitter:title']) ?? $this->text($xpath->query('//h1')->item(0)) ?? $this->text($xpath->query('//title')->item(0));
        $page->name = $page->name ? Str::limit(Str::squish(html_entity_decode($page->name, ENT_QUOTES | ENT_HTML5)), 250) : null;
        $page->image ??= $this->metaContent($xpath, ['og:image', 'twitter:image']);
        $page->image = $page->image ? $this->absolute($page->image, $url) : null;
        $page->lowest30dPrice ??= $this->lowest30d($xpath);
        $page->currency = $page->hasPrice() ? strtoupper($page->currency ?: 'PLN') : $page->currency;

        if ($page->regularPrice !== null && $page->price !== null && $page->regularPrice <= $page->price) {
            $page->regularPrice = null;
        }

        return $page;
    }

    /**
     * Widoczny tekst strony od nazwy produktu (h1), do odczytu przez AI.
     */
    public function visibleText(string $html, int $limit): string
    {
        $xpath = $this->xpath($html);

        foreach ($xpath->query('//script|//style|//noscript|//svg|//nav|//footer|//header|//iframe|//template') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $text = Str::squish((string) $xpath->document->documentElement?->textContent);
        $heading = $this->text($xpath->query('//h1')->item(0));

        if ($heading && ($position = mb_strpos($text, $heading)) !== false) {
            $text = mb_substr($text, max(0, $position - 300));
        }

        return mb_substr($text, 0, $limit);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function fromJsonLd(DOMXPath $xpath, string $url): ProductPage
    {
        $products = [];

        foreach ($xpath->query("//script[@type='application/ld+json']") as $script) {
            $data = $this->decodeJson((string) $script->textContent);

            if ($data !== null) {
                $this->collectProducts($data, $products);
            }
        }

        $page = new ProductPage;

        foreach ($products as $product) {
            $candidate = $this->readProduct($product, $url);

            if ($candidate->hasPrice()) {
                $candidate->method = 'json-ld';

                return $candidate->fillFrom($page);
            }

            $page->fillFrom($candidate);
        }

        return $page;
    }

    private function decodeJson(string $json): mixed
    {
        $json = trim($json);
        $data = json_decode($json, true);

        if ($data === null) {
            // Sklepy zostawiają znaki sterujące i przecinki przed nawiasem.
            $clean = preg_replace(['/[\x00-\x1F]+/', '/,\s*([}\]])/'], [' ', '$1'], $json);
            $data = json_decode((string) $clean, true);
        }

        return $data;
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function collectProducts(mixed $node, array &$products): void
    {
        if (! is_array($node)) {
            return;
        }

        if (array_is_list($node)) {
            foreach ($node as $item) {
                $this->collectProducts($item, $products);
            }

            return;
        }

        $types = array_map(fn ($type) => strtolower((string) $type), (array) ($node['@type'] ?? []));

        if (array_intersect($types, ['product', 'individualproduct', 'productmodel', 'productgroup']) !== []) {
            $products[] = $node;

            foreach ((array) ($node['hasVariant'] ?? []) as $variant) {
                if (is_array($variant)) {
                    $products[] = $variant + ['name' => $node['name'] ?? null, 'image' => $node['image'] ?? null, 'brand' => $node['brand'] ?? null];
                }
            }
        }

        foreach (['@graph', 'mainEntity', 'itemListElement', 'item'] as $key) {
            if (isset($node[$key])) {
                $this->collectProducts($node[$key], $products);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function readProduct(array $product, string $url): ProductPage
    {
        $offer = $this->pickOffer($this->offers($product['offers'] ?? []), $url);
        $brand = $product['brand'] ?? null;
        $gtin = collect(['gtin13', 'gtin', 'gtin12', 'gtin14', 'gtin8', 'isbn'])->map(fn ($key) => $product[$key] ?? null)->first(fn ($value) => is_scalar($value) && preg_match('/^\d{8,14}$/', (string) $value));
        $mpn = is_scalar($product['mpn'] ?? null) && trim((string) $product['mpn']) !== '' ? trim((string) $product['mpn']) : null;

        return new ProductPage(
            name: is_string($product['name'] ?? null) ? $product['name'] : null,
            price: $offer['price'] ?? null,
            currency: $offer['currency'] ?? null,
            available: $offer['available'] ?? null,
            regularPrice: $offer['regular'] ?? null,
            image: $this->imageUrl($product['image'] ?? null),
            brand: is_array($brand) ? (is_string($brand['name'] ?? null) ? $brand['name'] : null) : (is_string($brand) ? $brand : null),
            gtin: $gtin !== null ? (string) $gtin : null,
            mpn: $mpn,
        );
    }

    /**
     * Oferty z JSON-LD jako płaska lista (Offer, AggregateOffer z offers, lista).
     *
     * @return list<array{price: ?float, currency: ?string, available: ?bool, regular: ?float, url: ?string}>
     */
    private function offers(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        if (array_is_list($node)) {
            return collect($node)->flatMap(fn ($item) => $this->offers($item))->values()->all();
        }

        $result = [];
        $specs = isset($node['priceSpecification']) ? (array_is_list((array) $node['priceSpecification']) ? $node['priceSpecification'] : [$node['priceSpecification']]) : [];
        $regular = null;
        $specPrice = null;

        foreach ($specs as $spec) {
            if (! is_array($spec)) {
                continue;
            }

            $type = strtolower((string) ($spec['priceType'] ?? ''));

            if (str_contains($type, 'strikethrough') || str_contains($type, 'listprice')) {
                $regular = PriceParser::parse($spec['price'] ?? null);
            } else {
                $specPrice ??= PriceParser::parse($spec['price'] ?? null);
            }
        }

        $price = PriceParser::parse($node['price'] ?? null) ?? PriceParser::parse($node['lowPrice'] ?? null) ?? $specPrice;

        if ($price !== null) {
            $result[] = [
                'price' => $price,
                'currency' => is_string($node['priceCurrency'] ?? null) ? $node['priceCurrency'] : (is_string($specs[0]['priceCurrency'] ?? null) ? $specs[0]['priceCurrency'] : null),
                'available' => $this->availability($node['availability'] ?? null),
                'regular' => $regular,
                'url' => is_string($node['url'] ?? null) ? $node['url'] : null,
            ];
        }

        if (isset($node['offers'])) {
            array_push($result, ...$this->offers($node['offers']));
        }

        return $result;
    }

    /**
     * Oferta dla tej strony (ten sam adres), a gdy jej nie ma, najtańsza dostępna.
     *
     * @param  list<array{price: ?float, currency: ?string, available: ?bool, regular: ?float, url: ?string}>  $offers
     * @return array{price: ?float, currency: ?string, available: ?bool, regular: ?float, url: ?string}|null
     */
    private function pickOffer(array $offers, string $url): ?array
    {
        $offers = collect($offers);

        return $offers->first(fn (array $offer) => $offer['url'] && ProductUrl::samePage($offer['url'], $url) && $offer['available'] !== false)
            ?? $offers->where('available', '!==', false)->sortBy('price')->first()
            ?? $offers->first();
    }

    private function fromMeta(DOMXPath $xpath): ProductPage
    {
        $sale = PriceParser::parse($this->metaContent($xpath, ['product:sale_price:amount', 'og:sale_price:amount']));
        $price = PriceParser::parse($this->metaContent($xpath, ['product:price:amount', 'og:price:amount', 'product:price', 'twitter:data1']));
        $regular = PriceParser::parse($this->metaContent($xpath, ['product:original_price:amount', 'og:original_price:amount']));

        return new ProductPage(
            price: $sale ?? $price,
            currency: $this->metaContent($xpath, ['product:price:currency', 'og:price:currency', 'product:sale_price:currency']),
            available: $this->availability($this->metaContent($xpath, ['product:availability', 'og:availability'])),
            regularPrice: $sale !== null ? ($regular ?? $price) : $regular,
        );
    }

    private function fromMicrodata(DOMXPath $xpath): ProductPage
    {
        $scope = $xpath->query("//*[@itemtype and contains(translate(@itemtype, 'PRODUCT', 'product'), 'schema.org/product')]")->item(0);
        $find = fn (string $prop) => $xpath->query(".//*[@itemprop='{$prop}']", $scope ?? $xpath->document)->item(0);
        $value = function (?DOMNode $node): ?string {
            if (! $node instanceof DOMElement) {
                return null;
            }

            foreach (['content', 'href', 'value', 'data-price'] as $attribute) {
                if ($node->hasAttribute($attribute) && trim($node->getAttribute($attribute)) !== '') {
                    return trim($node->getAttribute($attribute));
                }
            }

            return $this->text($node);
        };

        return new ProductPage(
            name: $scope ? $value($find('name')) : null,
            price: PriceParser::parse($value($find('price')) ?? $value($find('lowPrice'))),
            currency: $value($find('priceCurrency')),
            available: $this->availability($value($find('availability'))),
            image: $scope ? $value($find('image')) : null,
        );
    }

    private function fromShopLayout(DOMXPath $xpath): ProductPage
    {
        foreach (self::PRICE_SELECTORS as $selector) {
            $node = $xpath->query($selector)->item(0);
            $price = $node ? PriceParser::parse($node->nodeValue) : null;

            if ($price !== null) {
                return new ProductPage(price: $price, currency: 'PLN');
            }
        }

        return new ProductPage;
    }

    /**
     * Pierwsza cena w złotych w treści zaraz po nazwie produktu (h1).
     */
    private function fromText(DOMXPath $xpath): ProductPage
    {
        $heading = $xpath->query('//h1')->item(0);

        if ($heading === null) {
            return new ProductPage;
        }

        $text = '';

        for ($node = $heading; $node !== null && mb_strlen($text) < 2500; $node = $this->nextNode($node)) {
            if ($node instanceof DOMElement && in_array(strtolower($node->tagName), ['script', 'style', 'noscript', 's', 'del', 'strike'], true)) {
                continue;
            }

            if ($node->nodeType === XML_TEXT_NODE && ! $this->insideSkipped($node)) {
                $text .= ' '.$node->nodeValue;
            }
        }

        if (preg_match(self::PRICE_IN_TEXT, Str::squish($text), $match) !== 1) {
            return new ProductPage;
        }

        return new ProductPage(price: PriceParser::parse($match[1]), currency: 'PLN');
    }

    private function nextNode(DOMNode $node): ?DOMNode
    {
        if ($node->firstChild !== null && ! ($node instanceof DOMElement && in_array(strtolower($node->tagName), ['script', 'style', 'noscript', 's', 'del', 'strike'], true))) {
            return $node->firstChild;
        }

        while ($node !== null) {
            if ($node->nextSibling !== null) {
                return $node->nextSibling;
            }

            $node = $node->parentNode;
        }

        return null;
    }

    private function insideSkipped(DOMNode $node): bool
    {
        for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (in_array(strtolower($parent->tagName), ['script', 'style', 'noscript', 's', 'del', 'strike'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Najniższa cena z 30 dni przed obniżką: 4 599,00 zł" (dyrektywa Omnibus).
     */
    private function lowest30d(DOMXPath $xpath): ?float
    {
        $body = $xpath->query('//body')->item(0);
        $text = Str::squish((string) $body?->textContent);

        if (preg_match('/najni[żz]sza cena[^0-9]{0,80}?30 dni[^0-9]{0,60}?(\d[\d \x{00A0}.,]*\d)\s?(?:zł|PLN)/ui', $text, $match) === 1) {
            return PriceParser::parse($match[1]);
        }

        return null;
    }

    private function availability(mixed $value): ?bool
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = strtolower(preg_replace('#^https?://schema\.org/#i', '', trim($value)));
        $value = str_replace([' ', '_', '-'], '', $value);

        return match (true) {
            in_array($value, ['outofstock', 'oos', 'soldout', 'discontinued', 'outofstockonline', 'false', 'niedostepny'], true) => false,
            in_array($value, ['instock', 'instoreonly', 'onlineonly', 'limitedavailability', 'preorder', 'presale', 'backorder', 'madetoorder', 'true', 'available', 'dostepny'], true) => true,
            default => null,
        };
    }

    private function metaContent(DOMXPath $xpath, array $names): ?string
    {
        foreach ($names as $name) {
            $node = $xpath->query("//meta[@property='{$name}' or @name='{$name}' or @itemprop='{$name}']/@content")->item(0);

            if ($node !== null && trim((string) $node->nodeValue) !== '') {
                return trim((string) $node->nodeValue);
            }
        }

        return null;
    }

    private function imageUrl(mixed $image): ?string
    {
        return match (true) {
            is_string($image) && $image !== '' => $image,
            is_array($image) && array_is_list($image) => $this->imageUrl($image[0] ?? null),
            is_array($image) => $this->imageUrl($image['url'] ?? $image['contentUrl'] ?? null),
            default => null,
        };
    }

    private function absolute(string $value, string $base): string
    {
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($base, PHP_URL_HOST);

        return str_starts_with($value, '//') ? "{$scheme}:{$value}" : "{$scheme}://{$host}/".ltrim($value, '/');
    }

    private function text(?DOMNode $node): ?string
    {
        $text = $node ? Str::squish((string) $node->textContent) : '';

        return $text !== '' ? $text : null;
    }
}
