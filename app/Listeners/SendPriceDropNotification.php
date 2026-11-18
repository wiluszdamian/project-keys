<?php

namespace App\Listeners;

use App\Events\ListingPriceChanged;
use App\Models\ListingPriceChange;
use App\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Obniżka ceny oferty, o której już było powiadomienie. Tylko obniżki, gdy NOTIFY_PRICE_DROPS=true.
 * Wysyłamy tylko nową najniższą cenę i raz na ofertę, nawet gdy pasuje do kilku wyszukiwań.
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
                ->exists()
            && $event->newPrice < $this->lowestPreviousPrice($event);
    }

    public function handle(ListingPriceChanged $event): void
    {
        $key = "notify:price-drop:{$event->listing->id}:{$event->newPrice}";

        if (! Cache::add($key, true, now()->addDays(30))) {
            return;
        }

        try {
            $this->notifier->priceDrop($event->search, $event->listing, $event->oldPrice, $event->newPrice);
        } catch (Throwable $exception) {
            Cache::forget($key);

            throw $exception;
        }
    }

    /**
     * Każda wcześniejsza cena jest old_price którejś zmiany, więc minimum z old_price
     * to najniższa cena przed obecną. Cena skacząca w górę i w dół nie daje kolejnych wiadomości.
     */
    private function lowestPreviousPrice(ListingPriceChanged $event): int
    {
        return (int) min($event->oldPrice, ListingPriceChange::where('listing_id', $event->listing->id)->min('old_price') ?? $event->oldPrice);
    }
}
