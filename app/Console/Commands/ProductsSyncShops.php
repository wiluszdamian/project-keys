<?php

namespace App\Console\Commands;

use App\Jobs\SyncProductShopJob;
use App\Models\ProductShop;
use App\Services\Products\ShopSitemaps;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('products:sync-shops {domain? : jeden sklep, od razu z wynikiem} {--all : wszystkie aktywne, także świeże}')]
#[Description('Monitor cen: pobiera mapy stron sklepów z listy (do darmowego szukania produktów)')]
class ProductsSyncShops extends Command
{
    public function handle(ShopSitemaps $sitemaps): int
    {
        if ($domain = $this->argument('domain')) {
            $shop = ProductShop::where('domain', $domain)->firstOrFail();
            $count = $sitemaps->sync($shop);
            $shop->refresh();
            $this->info("{$shop->domain}: {$count} adresów, status {$shop->status}".($shop->last_error ? " ({$shop->last_error})" : ''));

            return self::SUCCESS;
        }

        $shops = ProductShop::where('is_active', true)->get()->filter(fn (ProductShop $shop) => $this->option('all') || $shop->isStale());
        $shops->each(fn (ProductShop $shop) => SyncProductShopJob::dispatch($shop));
        $this->info("W kolejce: {$shops->count()}.");

        return self::SUCCESS;
    }
}
