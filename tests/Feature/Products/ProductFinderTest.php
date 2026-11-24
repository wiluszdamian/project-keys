<?php

use App\Ai\Agents\ProductMatchAgent;
use App\Filament\Support\Format;
use App\Models\Product;
use App\Models\ProductOffer;
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
        ->and($this->product->offers()->count())->toBe(2)
        ->and($this->product->refresh())->best_price->toBe(4599.0)->offers_searched_at->not->toBeNull()
        ->and($this->product->offers()->where('shop', 'tanszy.test')->first())->source->toBe('search')->match_note->toBe('Ten sam model, kolor i zestaw.')
        ->and(Cache::get("products:rejected:{$this->product->id}"))->toHaveCount(1);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.search.brave.com') && $request->header('X-Subscription-Token')[0] === 'brave-test');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'discord.com') && str_contains($request['embeds'][0]['description'], 'Taniej w tanszy.test: '.Format::pln(4599)));
});

it('bez AI porównuje nazwy', function () {
    config(['products.ai' => false]);

    $result = app(ProductFinder::class)->find($this->product, notify: false);

    expect($result['added'])->toBe(1)
        ->and($this->product->offers()->pluck('shop')->all())->toContain('tanszy.test')->not->toContain('inny.test');
});

it('bez klucza wyszukiwarki nie szuka', function () {
    config(['products.search.brave_key' => null]);

    app(ProductFinder::class)->find($this->product);
})->throws(RuntimeException::class, 'Brave');
