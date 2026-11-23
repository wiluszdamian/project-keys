<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\DealFinder;
use App\Services\DealNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('deals:refresh {--silent : Zapisz okazje bez powiadomień}')]
#[Description('Przelicza okazje (oferty tańsze niż rynek albo po obniżce) i wysyła powiadomienia o nowych.')]
class DealsRefresh extends Command
{
    public function handle(DealFinder $finder, DealNotifier $notifier): int
    {
        $activeSince = now()->subDays((int) config('deals.active_days'));

        // Oferty, których nie ma już na portalu, przestają być okazjami.
        $stale = Listing::whereNotNull('deal_at')->where('last_seen_at', '<', $activeSince)->update(['deal_reasons' => null, 'deal_at' => null]);

        $checked = 0;
        Listing::query()
            ->whereNull('duplicate_of_id')
            ->whereNotNull('price')
            ->where('last_seen_at', '>=', $activeSince)
            ->whereHas('searches', fn (Builder $query) => $query->where('is_active', true))
            ->chunkById(200, function ($listings) use ($finder, &$checked) {
                foreach ($listings as $listing) {
                    $finder->refresh($listing);
                    $checked++;
                }
            });

        if ($this->option('silent')) {
            Listing::whereNotNull('deal_at')->whereNull('deal_notified_at')->update(['deal_notified_at' => now()]);
            $sent = 0;
        } else {
            $sent = $notifier->notifyPending();
        }

        $this->info("Sprawdzone: {$checked}, okazje: ".Listing::whereNotNull('deal_at')->count().", wysłane: {$sent}, nieaktualne: {$stale}.");

        return self::SUCCESS;
    }
}
