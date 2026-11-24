<?php

namespace App\Services\Products;

use App\Filament\Support\Format;
use App\Jobs\ProductAdviceJob;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Notifications\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Monitor cen: dodawanie produktów i linków, sprawdzanie cen, historia i powiadomienia o zmianach.
 */
class ProductTracker
{
    public function __construct(private ProductReader $reader, private Notifier $notifier) {}

    /**
     * Nowy produkt z linku. Od razu sprawdza cenę, żeby znać nazwę. Błąd odczytu nie blokuje dodania.
     *
     * @param  array<string, mixed>  $attributes  ustawienia produktu (target_price, notify_*, note...)
     */
    public function add(string $url, array $attributes = []): Product
    {
        $normalized = ProductUrl::normalize($url);

        if (! ProductUrl::isPublic($normalized)) {
            throw new InvalidArgumentException('Ten adres nie prowadzi do publicznej strony.');
        }

        $existing = ProductOffer::where('url_key', sha1($normalized))->with('product')->first();

        if ($existing) {
            throw new InvalidArgumentException("Ten link już jest w monitorze: {$existing->product->name}.");
        }

        $product = DB::transaction(function () use ($normalized, $attributes) {
            $product = Product::create(['name' => $attributes['name'] ?? ProductUrl::shop($normalized)] + $attributes);
            $product->offers()->create([
                'url' => $normalized,
                'url_key' => sha1($normalized),
                'shop' => ProductUrl::shop($normalized),
                'is_primary' => true,
            ]);

            return $product;
        });

        $this->check($product, notify: false);

        return $product->refresh();
    }

    /**
     * Kolejny sklep dla produktu (link wklejony ręcznie albo znaleziony w sieci).
     */
    public function addOffer(Product $product, string $url, string $source = ProductOffer::SOURCE_MANUAL, ?ProductPage $page = null, ?string $matchNote = null): ProductOffer
    {
        $normalized = ProductUrl::normalize($url);

        if (! ProductUrl::isPublic($normalized)) {
            throw new InvalidArgumentException('Ten adres nie prowadzi do publicznej strony.');
        }

        if ($product->offers()->where('url_key', sha1($normalized))->exists()) {
            throw new InvalidArgumentException('Ten link już jest przy produkcie.');
        }

        $offer = $product->offers()->create([
            'url' => $normalized,
            'url_key' => sha1($normalized),
            'shop' => ProductUrl::shop($normalized),
            'source' => $source,
            'is_primary' => ! $product->offers()->exists(),
            'match_note' => $matchNote,
        ]);

        if ($page !== null) {
            $this->apply($offer, $page);
        } else {
            $this->checkOffer($offer);
        }

        return $offer;
    }

    /**
     * Sprawdza wszystkie śledzone oferty produktu, przelicza najlepszą cenę i wysyła powiadomienia.
     *
     * @return list<array{type: string, text: string}>
     */
    public function check(Product $product, bool $notify = true): array
    {
        $product->loadMissing('offers');
        $before = $this->snapshot($product);

        foreach ($product->offers->where('is_active', true) as $offer) {
            $this->checkOffer($offer);
        }

        $events = $this->refresh($product, $before);

        if ($notify && $events !== []) {
            $this->notifier->productEvents($product, $events);
        }

        if ($events !== [] || $product->ai_advice_at === null || $product->ai_advice_at->lt(now()->subHours((int) config('products.advice_every_hours')))) {
            if (ProductReader::aiEnabled() && $product->best_price !== null) {
                ProductAdviceJob::dispatch($product);
            }
        }

        return $events;
    }

    /**
     * Jedna oferta: pobranie, zapis ceny i historii albo błędu.
     */
    public function checkOffer(ProductOffer $offer): bool
    {
        try {
            $page = $this->reader->read($offer->url);
        } catch (ProductGoneException $exception) {
            $this->fail($offer, $exception->getMessage(), gone: true);

            return false;
        } catch (Throwable $exception) {
            $this->fail($offer, $exception->getMessage());

            return false;
        }

        $this->apply($offer, $page);

        return true;
    }

    /**
     * Zapisuje odczyt strony w ofercie i w historii (tylko pierwszy wpis i zmiany).
     */
    public function apply(ProductOffer $offer, ProductPage $page): void
    {
        $offer->forceFill([
            'title' => $page->name ?? $offer->title,
            'price' => $page->price,
            'regular_price' => $page->regularPrice,
            'lowest_30d_price' => $page->lowest30dPrice,
            'currency' => $page->currency ?? 'PLN',
            'available' => $page->available,
            'extracted_by' => $page->method,
            'last_checked_at' => now(),
            'last_error' => null,
            'failures' => 0,
        ])->save();

        $last = $offer->prices()->latest('checked_at')->latest('id')->first();

        if ($last === null || $last->price !== $page->price || $last->available !== $page->available) {
            $offer->prices()->create(['price' => $page->price, 'available' => $page->available, 'checked_at' => now()]);
        }

        $product = $offer->product;

        if ($offer->is_primary && $product) {
            $product->forceFill(array_filter([
                // Nazwa z linku zostaje zastąpiona nazwą ze sklepu, ale nie nadpisujemy nazwy wpisanej przez osobę.
                'name' => $product->name === $offer->shop && $page->name ? $page->name : null,
                'image_url' => $product->image_url ?: $page->image,
                'brand' => $product->brand ?: $page->brand,
                'gtin' => $product->gtin ?: $page->gtin,
                'mpn' => $product->mpn ?: $page->mpn,
            ], fn ($value) => $value !== null))->save();
        }
    }

    /**
     * Najlepsza cena, najniższa i najwyższa, oraz zdarzenia do powiadomienia.
     *
     * @param  array{best: ?float, shop: ?string, available: array<int, ?bool>, lowest: ?float}  $before
     * @return list<array{type: string, text: string}>
     */
    public function refresh(Product $product, array $before): array
    {
        $product->load('offers');
        $best = $product->offers
            ->filter(fn (ProductOffer $offer) => $offer->isBuyable())
            ->sortBy([fn ($a, $b) => $a->price <=> $b->price, fn ($a, $b) => $b->is_primary <=> $a->is_primary])
            ->first();
        $price = $best?->price;
        $events = [];
        $shop = $best ? " w {$best->shop}" : '';

        if ($before['best'] !== null && $price !== null && $price < $before['best']) {
            $percent = ($before['best'] - $price) / $before['best'] * 100;

            if ($product->notify_drops && $percent >= $product->min_drop_percent) {
                $low = $before['lowest'] !== null && $price < $before['lowest'] ? ' Najniższa cena od początku śledzenia.' : '';
                $events[] = ['type' => 'drop', 'text' => 'Cena spadła: '.Format::pln($before['best']).' → '.Format::pln($price).' ('.$this->percent(-$percent).")$shop.$low"];
            }
        }

        if ($before['best'] !== null && $price !== null && $price > $before['best'] && $product->notify_rises) {
            $percent = ($price - $before['best']) / $before['best'] * 100;
            $events[] = ['type' => 'rise', 'text' => 'Cena wzrosła: '.Format::pln($before['best']).' → '.Format::pln($price).' ('.$this->percent($percent).")$shop."];
        }

        if ($product->target_price !== null && $price !== null && $price <= $product->target_price && ($before['best'] === null || $before['best'] > $product->target_price) && $before['initialized']) {
            $events[] = ['type' => 'target', 'text' => 'Cena docelowa osiągnięta: '.Format::pln($price)."{$shop} (cel: ".Format::pln($product->target_price).').'];
        }

        if ($product->notify_stock) {
            foreach ($product->offers->where('is_active', true) as $offer) {
                $was = $before['available'][$offer->id] ?? null;

                if ($was === false && $offer->available === true) {
                    $events[] = ['type' => 'back_in_stock', 'text' => "Znowu dostępny w {$offer->shop}".($offer->price ? ' za '.Format::pln($offer->price) : '').'.'];
                } elseif ($was === true && $offer->available === false && $offer->is_primary) {
                    $events[] = ['type' => 'out_of_stock', 'text' => "Niedostępny w {$offer->shop}."];
                }
            }
        }

        $product->forceFill([
            'best_price' => $price,
            'best_offer_id' => $best?->id,
            'initial_price' => $product->initial_price ?? $price,
            'lowest_price' => $price !== null && ($product->lowest_price === null || $price < $product->lowest_price) ? $price : $product->lowest_price,
            'lowest_price_at' => $price !== null && ($product->lowest_price === null || $price < $product->lowest_price) ? now() : $product->lowest_price_at,
            'highest_price' => $price !== null && ($product->highest_price === null || $price > $product->highest_price) ? $price : $product->highest_price,
            'last_checked_at' => now(),
        ])->save();

        return $events;
    }

    /**
     * Stan przed sprawdzeniem, do porównania po nim.
     *
     * @return array{best: ?float, shop: ?string, available: array<int, ?bool>, lowest: ?float, initialized: bool}
     */
    public function snapshot(Product $product): array
    {
        $product->loadMissing('offers');

        return [
            'best' => $product->best_price,
            'shop' => $product->offers->firstWhere('id', $product->best_offer_id)?->shop,
            'available' => $product->offers->mapWithKeys(fn (ProductOffer $offer) => [$offer->id => $offer->last_checked_at ? $offer->available : null])->all(),
            'lowest' => $product->lowest_price,
            'initialized' => $product->initial_price !== null,
        ];
    }

    private function fail(ProductOffer $offer, string $message, bool $gone = false): void
    {
        $offer->forceFill([
            'last_error' => mb_substr($message, 0, 1000),
            'failures' => $offer->failures + 1,
            'last_checked_at' => now(),
            'available' => $gone ? false : $offer->available,
        ])->save();

        if ($gone) {
            $last = $offer->prices()->latest('checked_at')->latest('id')->first();

            if ($last?->available !== false) {
                $offer->prices()->create(['price' => $offer->price, 'available' => false, 'checked_at' => now()]);
            }
        }

        Log::info("Monitor cen: {$offer->shop} (oferta #{$offer->id}): {$message}");

        if ($offer->failures === (int) config('products.alert_after_failures') && ! $gone) {
            $this->notifier->alert(
                "product-offer:{$offer->id}",
                "Monitor cen: nie mogę odczytać ceny w {$offer->shop}",
                ($offer->product?->name ? $offer->product->name.'. ' : '')."Błąd {$offer->failures} raz z rzędu: {$message}",
            );
        }
    }

    private function percent(float $value): string
    {
        return ($value > 0 ? '+' : '−').number_format(abs($value), abs($value) < 10 ? 1 : 0, ',', ' ').'%';
    }
}
