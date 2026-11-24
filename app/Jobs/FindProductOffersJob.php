<?php

namespace App\Jobs;

use App\Models\Product;
use App\Notifications\PanelNotifier;
use App\Services\Products\ProductFinder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Szuka produktu w innych sklepach. Na kolejce ai, bo porównuje oferty przez AI.
 */
class FindProductOffersJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 10;

    public array $backoff = [10, 60, 300];

    public int $uniqueFor = 1800;

    public function __construct(public Product $product, public bool $report = false)
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

    public function handle(ProductFinder $finder, PanelNotifier $panel): void
    {
        $result = $finder->find($this->product);

        // Wynik do dzwoneczka, gdy szukanie uruchomiła osoba (z panelu albo API).
        if ($this->report) {
            $panel->productSearch($this->product, $result);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        if ($this->report) {
            app(PanelNotifier::class)->productSearchFailed($this->product, (string) $exception?->getMessage());
        }
    }
}
