<?php

namespace App\Services;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\Search;
use App\Notifications\Notifier;
use App\Notifications\PanelNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Powiadomienia o okazjach: raz na ofertę. Oferta, która już przyszła jako nowa oferta i była wtedy okazją,
 * nie dostaje drugiej wiadomości (wiadomość o nowej ofercie ma pole "Okazja").
 */
class DealNotifier
{
    public function __construct(private Notifier $notifier, private PanelNotifier $panel) {}

    /**
     * @return int ile okazji poszło
     */
    public function notifyPending(): int
    {
        $pending = Listing::query()
            ->whereNotNull('deal_at')
            ->whereNull('deal_notified_at')
            ->whereNull('duplicate_of_id')
            ->where(fn (Builder $query) => $query->whereNull('status')->orWhere('status', '!=', ListingStatus::Rejected->value))
            ->whereHas('searches', fn (Builder $query) => $query->where('is_active', true))
            ->with(['searches' => fn ($query) => $query->where('is_active', true)])
            ->withMax('analyses', 'score')
            ->get();

        // Już wysłane jako nowa oferta, gdy była okazją: bez drugiej wiadomości.
        [$alreadySent, $toSend] = $pending->partition(fn (Listing $listing) => $this->sentAsNewWhileDeal($listing));
        Listing::whereKey($alreadySent->modelKeys())->update(['deal_notified_at' => now()]);

        if ($toSend->isEmpty()) {
            return 0;
        }

        if (config('notify.deals')) {
            foreach ($toSend->groupBy(fn (Listing $listing) => $listing->deal_type->value) as $group) {
                $this->send($group);
            }
        }

        Listing::whereKey($toSend->modelKeys())->update(['deal_notified_at' => now()]);

        return config('notify.deals') ? $toSend->count() : 0;
    }

    /**
     * @param  Collection<int, Listing>  $listings  jeden typ transakcji
     */
    private function send(Collection $listings): void
    {
        if ($listings->count() > (int) config('deals.max_single_messages')) {
            $listings->each(fn (Listing $listing) => $listing->setAttribute('score', $listing->analyses_max_score));
            $this->notifier->deals($listings->first()->deal_type, $listings->sortByDesc('score')->values());
            $this->panel->deals($listings->count());

            return;
        }

        foreach ($listings as $listing) {
            $search = $listing->searches->firstWhere('notify_enabled', true);

            if ($search instanceof Search && $this->notifier->canNotify($search)) {
                $this->notifier->deal($search, $listing, $listing->analyses()->where('search_id', $search->id)->first());
            }

            $this->panel->deal($listing);
        }
    }

    private function sentAsNewWhileDeal(Listing $listing): bool
    {
        $sentAt = DB::table('search_listing')->where('listing_id', $listing->id)->max('notified_at');

        return $sentAt !== null && $listing->deal_at !== null && $listing->deal_at->lte($sentAt);
    }
}
