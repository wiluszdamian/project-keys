<?php

use App\Jobs\FindProductOffersJob;
use App\Models\Product;
use App\Models\ProductOffer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Queue::fake();
    actingAsOwner();
});

function apiShopPage(float $price): string
{
    return '<html><head><meta property="og:title" content="Fotelik Cloud G3"><meta property="product:price:amount" content="'.$price.'"><meta property="product:price:currency" content="PLN"></head><body><h1>Fotelik Cloud G3</h1></body></html>';
}

it('dodaje produkt, pokazuje go i historię cen', function () {
    Http::fake(['sklep.test/*' => Http::response(apiShopPage(1599))]);

    $id = $this->postJson('/api/products', ['url' => 'https://sklep.test/cloud-g3?utm_source=x', 'target_price' => 1400, 'notify_rises' => true])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Fotelik Cloud G3')
        ->assertJsonPath('data.best_price', 1599)
        ->assertJsonPath('data.target_price', 1400)
        ->assertJsonPath('data.notify_rises', true)
        ->assertJsonPath('data.offers.0.url', 'https://sklep.test/cloud-g3')
        ->assertJsonPath('data.offers.0.extracted_by', 'meta')
        ->json('data.id');

    $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/products/{$id}/prices")->assertOk()->assertJsonPath('data.0.price', 1599)->assertJsonPath('data.0.shop', 'sklep.test');
    $this->patchJson("/api/products/{$id}", ['target_price' => 1500, 'check_every_hours' => 6])->assertOk()->assertJsonPath('data.target_price', 1500)->assertJsonPath('data.check_every_hours', 6);
});

it('sprawdza cenę na żądanie i zwraca zdarzenia', function () {
    Http::fakeSequence('sklep.test/*')->push(apiShopPage(1599))->push(apiShopPage(1399));
    $id = $this->postJson('/api/products', ['url' => 'https://sklep.test/cloud-g3', 'target_price' => 1400])->json('data.id');

    $this->postJson("/api/products/{$id}/check")
        ->assertOk()
        ->assertJsonPath('data.best_price', 1399)
        ->assertJsonPath('data.target_reached', true)
        ->assertJsonPath('events.0.type', 'drop')
        ->assertJsonPath('events.1.type', 'target');
});

it('dodaje i usuwa sklep przy produkcie', function () {
    Http::fake(['inny.test/*' => Http::response(apiShopPage(1499))]);
    $product = Product::factory()->create(['best_price' => 1599, 'initial_price' => 1599]);
    ProductOffer::factory()->for($product)->create(['price' => 1599]);

    $offerId = $this->postJson("/api/products/{$product->id}/offers", ['url' => 'https://inny.test/cloud'])
        ->assertCreated()
        ->assertJsonPath('data.shop', 'inny.test')
        ->assertJsonPath('data.price', 1499)
        ->json('data.id');

    expect($product->refresh()->best_price)->toBe(1499.0);

    $this->postJson("/api/products/{$product->id}/offers", ['url' => 'https://inny.test/cloud'])->assertUnprocessable()->assertJsonValidationErrors('url');
    $this->deleteJson("/api/products/{$product->id}/offers/{$offerId}")->assertNoContent();

    expect($product->refresh()->best_price)->toBe(1599.0);
});

it('szukanie w innych sklepach idzie w tle, bez klucza 422', function () {
    $product = Product::factory()->create();

    $this->postJson("/api/products/{$product->id}/find-offers")->assertStatus(422);

    config(['products.search.brave_key' => 'x']);
    $this->postJson("/api/products/{$product->id}/find-offers")->assertStatus(202);

    Queue::assertPushed(FindProductOffersJob::class, fn ($job) => $job->report);
});

it('waliduje link i usuwa produkt', function () {
    $this->postJson('/api/products', ['url' => 'nie-link'])->assertUnprocessable()->assertJsonValidationErrors('url');

    $product = Product::factory()->create();
    $this->deleteJson("/api/products/{$product->id}")->assertNoContent();

    expect(Product::count())->toBe(0);
});
