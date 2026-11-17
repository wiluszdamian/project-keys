<?php

namespace App\Listeners;

use App\Events\ListingPriceChanged;
use App\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * Obniżka ceny oferty, o której już było powiadomienie. Tylko obniżki, gdy NOTIFY_PRICE_DROPS=true.
 */
class SendPriceDropNotification implements ShouldQueue
{
    public string $queue = 'notify';

    public function __construct(private Notifier $notifier) {}

    public function shouldQueue(ListingPriceChanged $event): bool
    {
        return config('notify.price_drops')
            && $event->newPrice < $event->oldPrice
            && $event->listing->duplicate_of_id === null
            && DB::table('search_listing')
                ->where('search_id', $event->search->id)
                ->where('listing_id', $event->listing->id)
                ->whereNotNull('notified_at')
                ->exists();
    }

    public function handle(ListingPriceChanged $event): void
    {
        $this->notifier->priceDrop($event->search, $event->listing, $event->oldPrice, $event->newPrice);
    }
}
