<?php

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Support\Format;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

beforeEach(function () {
    Sleep::fake();
    Queue::fake();
    $this->actingAs(User::factory()->create());
    Filament::setCurrentPanel('admin');
});

it('otwiera listę, produkt z wykresem i ustawienia produktu', function () {
    $product = Product::factory()->create([
        'name' => 'Wózek Balios', 'best_price' => 4599, 'initial_price' => 4999, 'lowest_price' => 4599, 'target_price' => 4600,
        'ai_advice' => ['verdict' => 'buy', 'score' => 8, 'summary' => 'Dobra cena.', 'reasons' => ['taniej o 400 zł'], 'tips' => ['sprawdź dostawę'], 'price' => 4599],
        'ai_advice_at' => now(),
    ]);
    $offer = ProductOffer::factory()->for($product)->create(['shop' => 'babyhit.pl', 'price' => 4599, 'regular_price' => 4999]);
    $offer->prices()->create(['price' => 4999, 'available' => true, 'checked_at' => now()->subDays(3)]);
    $offer->prices()->create(['price' => 4599, 'available' => true, 'checked_at' => now()]);
    $product->forceFill(['best_offer_id' => $offer->id])->save();

    $this->get('/admin/products')->assertOk()->assertSee('Wózek Balios')->assertSee(Format::pln(4599));
    $this->get("/admin/products/{$product->id}")->assertOk()->assertSee('Historia ceny')->assertSee('Kupuj')->assertSee('babyhit.pl')->assertSee('Dobra cena.');
    $this->get("/admin/products/{$product->id}/edit")->assertOk();
    $this->get('/admin/settings?tab=produkty::data::tab')->assertOk()->assertSee('Klucz Brave Search API');
    $this->get('/admin/product-shops')->assertOk()->assertSee('bobowozki.com.pl');
});

it('dodaje produkt z wklejonego linku', function () {
    Http::fake(['sklep.test/*' => Http::response('<html><head><meta property="og:title" content="Nosidło"><meta property="product:price:amount" content="459"></head><body></body></html>')]);

    Livewire::test(ListProducts::class)
        ->callAction('add', ['url' => 'https://sklep.test/nosidlo?utm_source=x', 'target_price' => 400])
        ->assertHasNoActionErrors();

    expect(Product::first())->name->toBe('Nosidło')->best_price->toBe(459.0)->target_price->toBe(400.0);
});

it('sprawdza cenę z poziomu produktu', function () {
    Http::fake(['sklep.test/*' => Http::response('<html><head><meta property="og:title" content="Nosidło"><meta property="product:price:amount" content="439"></head><body></body></html>')]);
    $product = Product::factory()->create(['best_price' => 459, 'initial_price' => 459, 'lowest_price' => 459]);
    ProductOffer::factory()->for($product)->create(['price' => 459, 'url' => 'https://sklep.test/nosidlo']);

    Livewire::test(ViewProduct::class, ['record' => $product->id])->callAction('check');

    expect($product->refresh()->best_price)->toBe(439.0);
});
