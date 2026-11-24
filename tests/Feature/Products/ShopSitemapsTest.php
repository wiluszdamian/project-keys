<?php

use App\Models\Product;
use App\Models\ProductShop;
use App\Services\Products\RobotsTxt;
use App\Services\Products\ShopSitemaps;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    ProductShop::query()->delete();
});

it('czyta robots.txt: mapy stron i zakazy dla wszystkich robotów', function () {
    $robots = RobotsTxt::parse("User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nDisallow: /catalogsearch/\nDisallow: *search?q=\nAllow: /catalogsearch/ok\nSitemap: https://sklep.test/sitemap.xml\n");

    expect($robots->sitemaps)->toBe(['https://sklep.test/sitemap.xml'])
        ->and($robots->allows('https://sklep.test/wozek-x'))->toBeTrue()
        ->and($robots->allows('https://sklep.test/catalogsearch/result/?q=x'))->toBeFalse()
        ->and($robots->allows('https://sklep.test/pl/search?q=balios'))->toBeFalse()
        ->and($robots->allows('https://sklep.test/catalogsearch/ok'))->toBeTrue();
});

it('pobiera mapę strony (też .gz) i pomija adresy zakazane w robots.txt', function () {
    $shop = ProductShop::create(['domain' => 'sklep.test']);
    Http::fake([
        'sklep.test/robots.txt' => Http::response("User-agent: *\nDisallow: /ukryte/\nSitemap: https://sklep.test/sitemap.xml.gz"),
        'sklep.test/sitemap.xml.gz' => Http::response(gzencode('<urlset><url><loc>https://sklep.test/cybex-balios-s-lux-moon-black.html</loc></url><url><loc><![CDATA[https://sklep.test/ukryte/cybex-balios]]></loc></url><url><loc>https://inny.test/cybex-balios</loc></url></urlset>')),
    ]);

    expect(app(ShopSitemaps::class)->sync($shop))->toBe(1)
        ->and($shop->refresh())->status->toBe('ok')->urls_count->toBe(1)
        ->and($shop->urls()->first()->slug)->toBe('cybex balios s lux moon black');
});

it('zapisuje stan, gdy sklep nie ma mapy strony', function () {
    $shop = ProductShop::create(['domain' => 'brak.test']);
    Http::fake(['brak.test/*' => Http::response('', 404)]);

    app(ShopSitemaps::class)->sync($shop);

    expect($shop->refresh())->status->toBe('error')->last_error->toContain('404')->urls_count->toBe(0);
});

it('szuka po słowach z nazwy, najlepsze najpierw, najwyżej 3 z jednego sklepu', function () {
    $shop = ProductShop::create(['domain' => 'sklep.test']);
    $other = ProductShop::create(['domain' => 'inny.test', 'is_active' => false]);

    foreach ([
        'https://sklep.test/cybex-balios-s-lux-2026-wozek-2w1',
        'https://sklep.test/cybex-balios-s-lux-2-0-wozek-3w1-cloud-t',
        'https://sklep.test/folia-cybex-balios-s-lux-talos-s-lux-akcesoria-do-wozka',
        'https://sklep.test/cybex-adaptery-balios-s-lux',
        'https://sklep.test/cybex-talos-s-lux',
        'https://sklep.test/balios-pokrowiec',
    ] as $url) {
        $shop->urls()->create(['url' => $url, 'slug' => ShopSitemaps::slug($url)]);
    }

    $other->urls()->create(['url' => 'https://inny.test/cybex-balios-s-lux', 'slug' => 'cybex balios s lux']);

    $results = app(ShopSitemaps::class)->search('Cybex Balios S Lux 2026');

    expect($results->pluck('url')->all())->toBe([
        'https://sklep.test/cybex-balios-s-lux-2026-wozek-2w1',
        'https://sklep.test/cybex-adaptery-balios-s-lux',
        'https://sklep.test/cybex-balios-s-lux-2-0-wozek-3w1-cloud-t',
    ]);
});

it('krótkie zapytanie z nazwy produktu, gdy nie ma własnego', function () {
    expect(ShopSitemaps::queryFor(new Product(['name' => 'Cybex Balios S Lux 2026 Wózek Głęboko-Spacerowy (Chocolate Brown)'])))->toBe('Cybex Balios S Lux 2026')
        ->and(ShopSitemaps::queryFor(new Product(['name' => 'X', 'search_query' => 'Cybex Balios'])))->toBe('Cybex Balios')
        ->and(ShopSitemaps::tokens('Wózek Cybex Balios S-Lux dla dzieci'))->toBe(['cybex', 'balios', 'lux', 'dzieci']);
});

it('sklepy w API', function () {
    Queue::fake();
    actingAsOwner();

    $id = $this->postJson('/api/product-shops', ['domain' => 'https://www.Wozki.test/kategoria'])->assertCreated()->assertJsonPath('data.domain', 'wozki.test')->json('data.id');
    $this->postJson('/api/product-shops', ['domain' => 'wozki.test'])->assertUnprocessable();
    $this->patchJson("/api/product-shops/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->getJson('/api/product-shops')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/product-shops/{$id}/sync")->assertStatus(202);
});
