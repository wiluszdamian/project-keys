<?php

namespace App\Jobs;

use App\Models\ProductShop;
use App\Services\Products\ShopSitemaps;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pobiera mapę strony jednego sklepu (adresy produktów do szukania).
 */
class SyncProductShopJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(public ProductShop $shop)
    {
        $this->onQueue('scrape');
    }

    public function uniqueId(): string
    {
        return (string) $this->shop->id;
    }

    public function handle(ShopSitemaps $sitemaps): void
    {
        $sitemaps->sync($this->shop);
    }
}
