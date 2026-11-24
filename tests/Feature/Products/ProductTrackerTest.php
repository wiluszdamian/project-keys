<?php

use App\Ai\Agents\ProductAdviceAgent;
use App\Ai\Agents\ProductPageAgent;
use App\Filament\Support\Format;
use App\Jobs\ProductAdviceJob;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\User;
use App\Services\Products\ProductAdvisor;
use App\Services\Products\ProductTracker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

const SHOP_URL = 'https://sklep.test/wozek-balios.html';
const DISCORD_PRODUCTS = 'https://discord.com/api/webhooks/9/products';

function shopPage(?float $price, string $availability = 'InStock', string $name = 'Wózek Balios S Lux'): string
{
    $offer = $price === null ? '' : ',"offers":{"@type":"Offer","price":"'.$price.'","priceCurrency":"PLN","availability":"https://schema.org/'.$availability.'"}';

    return '<html><head><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"'.$name.'","image":"https://sklep.test/img.jpg","brand":{"@type":"Brand","name":"Cybex"}'.$offer.'}</script></head><body><h1>'.$name.'</h1></body></html>';
}

beforeEach(function () {
    Sleep::fake();
    Queue::fake();
    User::factory()->create();
    config(['notify.discord.webhook_url_products' => DISCORD_PRODUCTS, 'ai.providers.opencode.key' => null]);
});

it('dodaje produkt z linku: nazwa i cena ze sklepu, bez powiadomienia', function () {
    Http::fake([SHOP_URL => Http::response(shopPage(4999)), '*' => Http::response('', 500)]);

    $product = app(ProductTracker::class)->add(SHOP_URL.'?utm_source=google#top', ['target_price' => 4500]);

    expect($product)
        ->name->toBe('Wózek Balios S Lux')
        ->brand->toBe('Cybex')
        ->image_url->toBe('https://sklep.test/img.jpg')
        ->best_price->toBe(4999.0)
        ->initial_price->toBe(4999.0)
        ->lowest_price->toBe(4999.0)
        ->target_price->toBe(4500.0)
        ->and($product->offers)->toHaveCount(1)
        ->and($product->offers->first())->url->toBe(SHOP_URL)->is_primary->toBeTrue()->extracted_by->toBe('json-ld')
        ->and($product->prices()->count())->toBe(1);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'discord.com'));
});

it('nie dodaje tego samego linku drugi raz', function () {
    Http::fake([SHOP_URL => Http::response(shopPage(4999))]);
    app(ProductTracker::class)->add(SHOP_URL);

    app(ProductTracker::class)->add(SHOP_URL.'?utm_medium=x');
})->throws(InvalidArgumentException::class, 'już jest w monitorze');

it('przy obniżce do ceny docelowej wysyła Discorda i dzwoneczek', function () {
    Http::fakeSequence(SHOP_URL)->push(shopPage(4999))->push(shopPage(4999))->push(shopPage(4399));
    Http::fake([DISCORD_PRODUCTS => Http::response('', 204)]);
    $tracker = app(ProductTracker::class);
    $product = $tracker->add(SHOP_URL, ['target_price' => 4500]);

    expect($tracker->check($product))->toBe([]);

    $events = $tracker->check($product->refresh());

    expect(array_column($events, 'type'))->toBe(['drop', 'target'])
        ->and($events[0]['text'])->toContain(Format::pln(4999).' → '.Format::pln(4399))->toContain('−12%')->toContain('Najniższa cena od początku')
        ->and($product->refresh())->best_price->toBe(4399.0)->lowest_price->toBe(4399.0)->highest_price->toBe(4999.0)
        ->and($product->prices()->count())->toBe(2)
        ->and(User::first()->notifications()->count())->toBe(1);

    Http::assertSent(fn (Request $request) => $request->url() === DISCORD_PRODUCTS
        && str_contains($request['embeds'][0]['author']['name'], 'Cena docelowa')
        && $request['embeds'][0]['title'] === 'Wózek Balios S Lux');
});

it('daje znać o wyprzedaniu i powrocie do sprzedaży', function () {
    Http::fakeSequence(SHOP_URL)->push(shopPage(1000))->push(shopPage(990, 'OutOfStock'))->push(shopPage(980));
    Http::fake([DISCORD_PRODUCTS => Http::response('', 204)]);
    $tracker = app(ProductTracker::class);
    $product = $tracker->add(SHOP_URL, ['min_drop_percent' => 5]);

    $outOfStock = $tracker->check($product);
    $back = $tracker->check($product->refresh());

    expect(array_column($outOfStock, 'type'))->toBe(['out_of_stock'])
        ->and($product->refresh()->best_price)->toBe(980.0)
        ->and(array_column($back, 'type'))->toBe(['back_in_stock']);
});

it('liczy błędy odczytu i po trzecim wysyła alert', function () {
    Http::fake([SHOP_URL => Http::response('<html><body><h1>Produkt</h1><p>Zapytaj o cenę</p></body></html>')]);
    $tracker = app(ProductTracker::class);
    $product = $tracker->add(SHOP_URL);

    $tracker->check($product);
    $tracker->check($product);

    $offer = $product->offers()->first();

    expect($offer->failures)->toBe(3)
        ->and($offer->last_error)->toContain('Nie znalazłem ceny')
        ->and($product->refresh()->best_price)->toBeNull()
        ->and(User::first()->notifications()->count())->toBe(1);
});

it('strona 404 to produkt niedostępny w tym sklepie', function () {
    Http::fakeSequence(SHOP_URL)->push(shopPage(500))->push('', 404);
    $tracker = app(ProductTracker::class);
    $product = $tracker->add(SHOP_URL, ['notify_stock' => false]);

    $tracker->check($product);

    expect($product->offers()->first())->available->toBeFalse()->last_error->toContain('404')
        ->and($product->refresh()->best_price)->toBeNull();
});

it('gdy sklep nie podaje ceny w danych strony, odczytuje ją AI', function () {
    config(['ai.providers.opencode.key' => 'test-key', 'scoring.enabled' => true]);
    ProductPageAgent::fake([['found' => true, 'name' => 'Wanienka', 'price' => 189.99, 'regular_price' => 229, 'lowest_30d_price' => null, 'currency' => 'PLN', 'available' => true]]);
    Http::fake([SHOP_URL => Http::response('<html><body><h1>Wanienka</h1><div class="x">Cena tylko w skrypcie, którą widzi przeglądarka, tekst na tyle długi, żeby AI dostało coś do czytania.</div></body></html>')]);

    $product = app(ProductTracker::class)->add(SHOP_URL);

    expect($product->best_price)->toBe(189.99)
        ->and($product->offers->first())->extracted_by->toBe('ai')->regular_price->toBe(229.0);

    Queue::assertPushed(ProductAdviceJob::class);
});

it('najlepsza cena bierze najtańszy dostępny sklep', function () {
    $product = Product::factory()->create(['initial_price' => 1000, 'best_price' => 1000]);
    ProductOffer::factory()->for($product)->create(['price' => 1000, 'is_primary' => true]);
    $cheap = ProductOffer::factory()->for($product)->create(['price' => 900, 'is_primary' => false, 'shop' => 'tani.test']);
    ProductOffer::factory()->for($product)->create(['price' => 800, 'is_primary' => false, 'available' => false]);
    $tracker = app(ProductTracker::class);

    $tracker->refresh($product, $tracker->snapshot($product));

    expect($product->refresh())->best_price->toBe(900.0)->best_offer_id->toBe($cheap->id)
        ->and($product->changePercent())->toBe(-10.0);
});

it('AI ocenia cenę i zapisuje werdykt', function () {
    ProductAdviceAgent::fake([['verdict' => 'buy', 'score' => 9, 'summary' => 'Najniższa cena od miesiąca.', 'reasons' => ['o 600 zł taniej niż na start'], 'tips' => []]]);
    $product = Product::factory()->create(['best_price' => 4399, 'initial_price' => 4999, 'lowest_price' => 4399]);
    ProductOffer::factory()->for($product)->create(['price' => 4399]);

    $advice = app(ProductAdvisor::class)->advise($product);

    expect($advice)->verdict->toBe('buy')->score->toBe(9)->price->toBe(4399.0)
        ->and($product->refresh()->ai_advice['summary'])->toBe('Najniższa cena od miesiąca.')
        ->and(ProductAdvisor::prompt($product))->toContain('najlepsza obecna cena: '.Format::pln(4399))->toContain('pierwsza cena, gdy zaczęliśmy śledzić: '.Format::pln(4999));
});
