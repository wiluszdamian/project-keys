<?php

namespace App\Listeners;

use App\Events\ListingPriceChanged;
use App\Services\DealFinder;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Po zmianie ceny przeliczamy okazję. Powiadomienie wyśle deals:refresh (co godzinę).
 */
class RefreshDealOnPriceChange implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(private DealFinder $finder) {}

    public function handle(ListingPriceChanged $event): void
    {
        $this->finder->refresh($event->listing->fresh());
    }
}
