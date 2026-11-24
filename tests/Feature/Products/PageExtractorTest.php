<?php

use App\Services\Products\PageExtractor;
use App\Services\Products\PriceParser;
use App\Services\Products\ProductUrl;

const BABYHIT_URL = 'https://babyhit.pl/product-pol-143546-Cybex-Balios-S-Lux-2026-Wozek-Gleboko-Spacerowy-ze-Skladana-Gondola-Fold-Cloud-G3-I-Size-Fotelik-Samochodowy-Adaptery-Baza-G3-Chocolate-Brown.html';

function productHtml(string $body, string $head = ''): string
{
    return "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>Sklep</title>{$head}</head><body>{$body}</body></html>";
}

it('czyta cenę z danych strukturalnych (babyhit.pl, IdoSell)', function () {
    $page = app(PageExtractor::class)->extract(file_get_contents(base_path('tests/Fixtures/products/babyhit.html')), BABYHIT_URL);

    expect($page)
        ->price->toBe(4999.0)
        ->currency->toBe('PLN')
        ->available->toBeTrue()
        ->method->toBe('json-ld')
        ->brand->toBe('Cybex Gold')
        ->mpn->toBeNull()
        ->and($page->name)->toStartWith('Cybex Balios S Lux 2026 Wózek')
        ->and($page->image)->toStartWith('https://babyhit.pl/hpeciai/');
});

it('bez danych strukturalnych bierze cenę z układu sklepu IdoSell, nie cenę zestawu', function () {
    $html = preg_replace('#<script type="application/ld\+json">.*?</script>#s', '', file_get_contents(base_path('tests/Fixtures/products/babyhit.html')));

    $page = app(PageExtractor::class)->extract($html, BABYHIT_URL);

    expect($page->price)->toBe(4999.0)->and($page->method)->toBe('shop');
});

it('czyta cenę promocyjną z meta tagów', function () {
    $html = productHtml('<h1>Fotelik X</h1>', '<meta property="og:title" content="Fotelik X"><meta property="product:price:amount" content="1299.00"><meta property="product:sale_price:amount" content="999.00"><meta property="product:price:currency" content="PLN"><meta property="product:availability" content="instock">');

    $page = app(PageExtractor::class)->extract($html, 'https://sklep.test/fotelik');

    expect($page)->price->toBe(999.0)->regularPrice->toBe(1299.0)->available->toBeTrue()->method->toBe('meta')->name->toBe('Fotelik X');
});

it('czyta microdata i niedostępność', function () {
    $html = productHtml('<div itemscope itemtype="https://schema.org/Product"><h1 itemprop="name">Nosidło</h1><div itemprop="offers" itemscope itemtype="https://schema.org/Offer"><span itemprop="price" content="459.90">459,90 zł</span><meta itemprop="priceCurrency" content="PLN"><link itemprop="availability" href="https://schema.org/OutOfStock"></div></div>');

    $page = app(PageExtractor::class)->extract($html, 'https://sklep.test/nosidlo');

    expect($page)->price->toBe(459.9)->available->toBeFalse()->method->toBe('microdata')->name->toBe('Nosidło');
});

it('w JSON-LD wybiera wariant z adresu strony i cenę przekreśloną', function () {
    $json = json_encode(['@context' => 'https://schema.org', '@graph' => [['@type' => 'Product', 'name' => 'Wózek', 'gtin13' => '4063846312345', 'offers' => [
        ['@type' => 'Offer', 'price' => '3999', 'priceCurrency' => 'PLN', 'url' => 'https://sklep.test/wozek?variant=1', 'availability' => 'https://schema.org/InStock'],
        ['@type' => 'Offer', 'price' => '4299', 'priceCurrency' => 'PLN', 'url' => 'https://sklep.test/wozek-czarny', 'availability' => 'https://schema.org/InStock',
            'priceSpecification' => [['@type' => 'UnitPriceSpecification', 'price' => 4299], ['@type' => 'UnitPriceSpecification', 'priceType' => 'https://schema.org/StrikethroughPrice', 'price' => 4799]]],
    ]]]]);

    $page = app(PageExtractor::class)->extract(productHtml('<h1>Wózek</h1>', "<script type=\"application/ld+json\">{$json}</script>"), 'https://sklep.test/wozek-czarny');

    expect($page)->price->toBe(4299.0)->regularPrice->toBe(4799.0)->gtin->toBe('4063846312345');
});

it('na końcu bierze pierwszą cenę pod nazwą produktu, bez przekreślonej', function () {
    $html = productHtml('<nav>Koszyk 0,00 zł</nav><h1>Łóżeczko Bobas</h1><div class="cena"><del>1 499,00 zł</del> <b>1 299,99 zł</b></div><p>Dostawa od 19,99 zł</p><p>Najniższa cena z 30 dni przed obniżką: 1 349,00 zł</p>');

    $page = app(PageExtractor::class)->extract($html, 'https://sklep.test/lozeczko');

    expect($page)->price->toBe(1299.99)->method->toBe('text')->lowest30dPrice->toBe(1349.0)->name->toBe('Łóżeczko Bobas');
});

it('nie zgaduje ceny, gdy jej nie ma', function () {
    $page = app(PageExtractor::class)->extract(productHtml('<h1>Produkt</h1><p>Zapytaj o cenę</p>'), 'https://sklep.test/p');

    expect($page->hasPrice())->toBeFalse();
});

it('rozumie różne zapisy ceny', function (mixed $input, ?float $expected) {
    expect(PriceParser::parse($input))->toBe($expected);
})->with([
    ['4 999,00 zł', 4999.0],
    ['4999.00', 4999.0],
    ['1.299,99 PLN', 1299.99],
    ['1,299.99', 1299.99],
    ['12.500', 12500.0],
    ['12,5', 12.5],
    ["2\u{00A0}349 zł", 2349.0],
    [199, 199.0],
    ['brak', null],
    ['0', null],
]);

it('czyści link z parametrów śledzących i kotwicy', function () {
    $url = BABYHIT_URL.'?curr=PLN&country=1143020003&utm_source=iai_ads&utm_medium=google_shopping&gclid=abc#modalClose';

    expect(ProductUrl::normalize($url))->toBe(BABYHIT_URL.'?curr=PLN&country=1143020003')
        ->and(ProductUrl::shop('https://www.babyhit.pl/x'))->toBe('babyhit.pl')
        ->and(ProductUrl::key('HTTPS://BABYHIT.PL/a?utm_source=x'))->toBe(ProductUrl::key('https://babyhit.pl/a'));
});

it('nie pozwala na adresy z sieci lokalnej', function () {
    config(['products.block_private_hosts' => true]);

    expect(ProductUrl::isPublic('http://127.0.0.1/admin'))->toBeFalse()
        ->and(ProductUrl::isPublic('http://localhost:8080/'))->toBeFalse()
        ->and(ProductUrl::isPublic('http://10.0.0.5/'))->toBeFalse()
        ->and(ProductUrl::isPublic('http://8.8.8.8/'))->toBeTrue();
});

it('odrzuca link, który nie jest adresem strony', function () {
    ProductUrl::normalize('ftp://sklep.test/plik');
})->throws(InvalidArgumentException::class);
