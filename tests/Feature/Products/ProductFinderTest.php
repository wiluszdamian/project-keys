<?php

use App\Ai\Agents\ProductMatchAgent;
use App\Ai\Agents\ProductShopsAgent;
use App\Filament\Support\Format;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductShop;
use App\Models\User;
use App\Services\Products\ProductFinder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

function offerPage(string $name, float $price): string
{
    return '<html><head><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"'.$name.'","offers":{"@type":"Offer","price":"'.$price.'","priceCurrency":"PLN","availability":"https://schema.org/InStock"}}</script></head><body><h1>'.$name.'</h1></body></html>';
}

beforeEach(function () {
    Sleep::fake();
    Queue::fake();
    User::factory()->create();
    config(['products.search.brave_key' => 'brave-test', 'ai.providers.opencode.key' => 'test-key', 'scoring.enabled' => true]);
    // Bez sklepów startowych z migracji: test nie może pobierać prawdziwych map stron.
    ProductShop::query()->delete();
    ProductShopsAgent::fake([['query' => 'Cybex Balios S Lux', 'shops' => []]]);

    $this->product = Product::factory()->create(['name' => 'Cybex Balios S Lux 2026 Chocolate Brown', 'best_price' => 4999, 'initial_price' => 4999, 'lowest_price' => 4999]);
    $this->primary = ProductOffer::factory()->for($this->product)->create(['url' => 'https://babyhit.test/balios', 'shop' => 'babyhit.test', 'price' => 4999]);
    $this->product->forceFill(['best_offer_id' => $this->primary->id])->save();

    Http::fake([
        'api.search.brave.com/*' => Http::response(['web' => ['results' => [
            ['url' => 'https://babyhit.test/balios-inny-link', 'title' => 'Balios', 'description' => ''],
            ['url' => 'https://www.ceneo.pl/123', 'title' => 'Ceneo', 'description' => ''],
            ['url' => 'https://tanszy.test/cybex-balios-s-lux-chocolate', 'title' => 'Cybex Balios S Lux Chocolate Brown', 'description' => 'Wózek 2w1'],
            ['url' => 'https://inny.test/cybex-balios-s-lux-czarny', 'title' => 'Cybex Balios S Lux Moon Black', 'description' => ''],
        ]]]),
        'tanszy.test/*' => Http::response(offerPage('Cybex Balios S Lux 2026 Chocolate Brown', 4599)),
        'inny.test/*' => Http::response(offerPage('Cybex Balios S Lux 2026 Moon Black', 4199)),
        'discord.com/*' => Http::response('', 204),
    ]);
});

it('szuka w innych sklepach, AI odrzuca inny kolor, tańszy sklep daje powiadomienie', function () {
    ProductMatchAgent::fake([['matches' => [
        ['index' => 0, 'same' => true, 'reason' => 'Ten sam model, kolor i zestaw.'],
        ['index' => 1, 'same' => false, 'reason' => 'Inny kolor (Moon Black).'],
    ]]]);
    config(['notify.discord.webhook_url' => 'https://discord.com/api/webhooks/1/main']);

    $result = app(ProductFinder::class)->find($this->product);

    expect($result)->candidates->toBe(2)->added->toBe(1)->rejected->toBe(1)->errors->toBe(0)
        ->and($this->product->offers()->count())->toBe(3)
        ->and($this->product->offers()->where('shop', 'inny.test')->first())->is_active->toBeFalse()->matched->toBeFalse()->match_note->toBe('Inny kolor (Moon Black).')
        ->and($this->product->refresh())->best_price->toBe(4599.0)->offers_searched_at->not->toBeNull()
        ->and($this->product->offers()->where('shop', 'tanszy.test')->first())->source->toBe('search')->matched->toBeTrue()->match_note->toBe('Ten sam model, kolor i zestaw.')
        ->and(Cache::get("products:rejected:{$this->product->id}"))->toHaveCount(1);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.search.brave.com') && $request->header('X-Subscription-Token')[0] === 'brave-test');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'discord.com') && str_contains($request['embeds'][0]['description'], 'Taniej w tanszy.test: '.Format::pln(4599)));
});

it('bez AI porównuje nazwy', function () {
    config(['products.ai' => false]);

    $result = app(ProductFinder::class)->find($this->product, notify: false);

    expect($result['added'])->toBe(1)
        ->and($this->product->offers()->where('is_active', true)->pluck('shop')->all())->toContain('tanszy.test')->not->toContain('inny.test');
});

it('bez sklepów, AI i klucza wyszukiwarki nie szuka', function () {
    config(['products.search.brave_key' => null, 'products.ai' => false]);

    app(ProductFinder::class)->find($this->product);
})->throws(RuntimeException::class, 'Nie ma gdzie szukać');

it('szuka za darmo w mapach stron sklepów, które zaproponowało AI', function () {
    config(['products.search.brave_key' => null]);
    ProductShopsAgent::fake([['query' => 'Cybex Balios S Lux', 'shops' => [
        ['domain' => 'https://www.wozki.test/', 'name' => 'Wózki', 'why' => 'Sklep z wózkami.'],
        ['domain' => 'allegro.pl', 'name' => 'Allegro', 'why' => 'Marketplace.'],
    ]]]);
    ProductMatchAgent::fake([['matches' => [
        ['index' => 0, 'same' => true, 'reason' => 'Ten sam zestaw i kolor.'],
        ['index' => 1, 'same' => false, 'reason' => 'To adaptery, nie wózek.'],
    ]]]);
    Http::fake([
        'wozki.test/robots.txt' => Http::response("User-agent: *\nDisallow: /szukaj\nSitemap: https://www.wozki.test/sitemap_index.xml"),
        'www.wozki.test/sitemap_index.xml' => Http::response('<sitemapindex><sitemap><loc>https://www.wozki.test/sitemap-categories.xml</loc></sitemap><sitemap><loc>https://www.wozki.test/sitemap-products.xml</loc></sitemap></sitemapindex>'),
        'www.wozki.test/sitemap-products.xml' => Http::response('<urlset><url><loc>https://www.wozki.test/p/cybex-balios-s-lux-2026-chocolate-brown-cloud-g3</loc></url><url><loc>https://www.wozki.test/p/cybex-adaptery-balios-s-lux</loc></url><url><loc>https://www.wozki.test/p/joie-versatrax</loc></url></urlset>'),
        'www.wozki.test/p/cybex-balios-s-lux-2026-chocolate-brown-cloud-g3' => Http::response(offerPage('Cybex Balios S Lux 2026 Chocolate Brown + Cloud G3', 4699)),
        'www.wozki.test/p/cybex-adaptery-balios-s-lux' => Http::response(offerPage('Cybex adaptery do Balios S Lux', 129)),
        'discord.com/*' => Http::response('', 204),
    ]);

    $result = app(ProductFinder::class)->find($this->product, notify: false);

    $shop = ProductShop::firstWhere('domain', 'wozki.test');

    expect($this->product->refresh()->search_query)->toBe('Cybex Balios S Lux')
        ->and(ProductShop::pluck('domain')->all())->toBe(['wozki.test'])
        ->and($shop)->source->toBe('ai')->status->toBe('ok')->urls_count->toBe(3)
        ->and($result)->candidates->toBe(2)->added->toBe(1)
        ->and($this->product->offers()->where('shop', 'wozki.test')->where('is_active', true)->first())->price->toBe(4699.0)->source->toBe('search')
        ->and($this->product->offers()->where('shop', 'wozki.test')->where('is_active', false)->first())->price->toBe(129.0)->matched->toBeFalse();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'sitemap-categories') || str_contains($request->url(), 'brave.com') || str_contains($request->url(), 'joie'));
});
