<?php

namespace App\Console\Commands;

use App\Jobs\CheckProductJob;
use App\Models\Product;
use App\Services\Products\ProductTracker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('products:check {--id=* : tylko te produkty} {--sync : od razu, bez kolejki, z wynikiem}')]
#[Description('Monitor cen: sprawdza produkty, które czekają na sprawdzenie (albo podane)')]
class ProductsCheck extends Command
{
    public function handle(ProductTracker $tracker): int
    {
        if (! config('products.enabled')) {
            return self::SUCCESS;
        }

        $ids = array_map('intval', (array) $this->option('id'));
        $products = $ids ? Product::whereIn('id', $ids)->get() : Product::due()->orderBy('last_checked_at')->get();

        foreach ($products as $product) {
            if ($this->option('sync')) {
                $events = $tracker->check($product);
                $product->refresh();
                $this->line("#{$product->id} {$product->name}: ".($product->best_price !== null ? number_format($product->best_price, 2, ',', ' ').' zł' : 'brak ceny').($events ? ' · '.collect($events)->pluck('text')->implode(' ') : ''));

                foreach ($product->offers as $offer) {
                    $this->line("   {$offer->shop}: ".($offer->last_error ?? (($offer->price !== null ? number_format($offer->price, 2, ',', ' ').' zł' : '?').' ('.($offer->extracted_by ?? '?').')')));
                }
            } else {
                CheckProductJob::dispatch($product);
            }
        }

        if (! $this->option('sync')) {
            $this->info("W kolejce: {$products->count()}.");
        }

        return self::SUCCESS;
    }
}
