<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Products\ProductAdvisor;
use App\Services\Products\ProductReader;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Ocena AI ceny produktu (kupić czy poczekać).
 */
class ProductAdviceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 150;

    public int $tries = 20;

    public array $backoff = [10, 60, 300];

    public int $uniqueFor = 600;

    public function __construct(public Product $product)
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return (string) $this->product->id;
    }

    public function middleware(): array
    {
        return [new RateLimited('ai')];
    }

    public function handle(ProductAdvisor $advisor): void
    {
        if (ProductReader::aiEnabled() && $this->product->fresh()?->best_price !== null) {
            $advisor->advise($this->product->fresh());
        }
    }
}
