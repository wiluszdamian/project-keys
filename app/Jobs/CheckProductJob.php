<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Products\ProductTracker;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sprawdza ceny jednego produktu we wszystkich śledzonych sklepach.
 */
class CheckProductJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public Product $product)
    {
        $this->onQueue('scrape');
    }

    public function uniqueId(): string
    {
        return (string) $this->product->id;
    }

    public function handle(ProductTracker $tracker): void
    {
        $tracker->check($this->product);
    }
}
