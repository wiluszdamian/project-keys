<?php

namespace App\Console\Commands;

use App\Enums\DealType;
use App\Enums\NotifyMode;
use App\Jobs\SendDigestJob;
use App\Jobs\SendGroupDigestJob;
use App\Models\Listing;
use App\Models\Search;
use App\Notifications\Notifier;
use App\Notifications\NotifyGroups;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Signature('notify:send-scheduled')]
#[Description('Wysyła zebrane oferty: podsumowania kanałów (wynajem, sprzedaż) i wyszukiwań z powiadomieniami co kilka godzin albo raz dziennie')]
class NotifySendScheduled extends Command
{
    public function handle(Notifier $notifier): int
    {
        $now = now();

        foreach (DealType::cases() as $dealType) {
            if (NotifyGroups::isCombined($dealType) && NotifyGroups::due($dealType, $now)) {
                $this->sendGroup($dealType, $notifier, $now);
            }
        }

        foreach (Search::where('notify_enabled', true)->get() as $search) {
            // Kanał zbiorczy wysyła za wszystkie wyszukiwania tego typu.
            if (NotifyGroups::isCombined($search->deal_type) || ! $search->scheduledNotifyDue($now)) {
                continue;
            }

            $rows = DB::table('search_listing')
                ->where('search_id', $search->id)
                ->whereNull('notified_at')
                ->where('notify_skipped_reason', 'scheduled');

            // Oferta wysłana w międzyczasie z innego wyszukiwania nie idzie drugi raz.
            $alreadySent = Listing::whereKey((clone $rows)->pluck('listing_id'))->get()
                ->filter(fn (Listing $listing) => $listing->wasNotified($search->id))
                ->modelKeys();
            (clone $rows)->whereIn('listing_id', $alreadySent)->update(['notify_skipped_reason' => 'already_sent']);

            $ids = (clone $rows)->pluck('listing_id')->all();

            if ($ids !== []) {
                SendDigestJob::dispatch($search, $ids, false, $search->scheduledPeriodLabel());
                $this->line("#{$search->id}: podsumowanie z ".count($ids).' ofert');
            }

            // Raz dziennie: termin mija także bez ofert. Co kilka godzin: licznik startuje od wysyłki.
            if ($ids !== [] || $search->notify_mode === NotifyMode::Daily) {
                $search->forceFill(['notify_last_sent_at' => $now])->save();
            }
        }

        return self::SUCCESS;
    }

    private function sendGroup(DealType $dealType, Notifier $notifier, Carbon $now): void
    {
        $rows = DB::table('search_listing')
            ->join('searches', 'searches.id', '=', 'search_listing.search_id')
            ->where('searches.deal_type', $dealType->value)
            ->where('searches.notify_enabled', true)
            ->whereNull('search_listing.notified_at')
            ->where('search_listing.notify_skipped_reason', 'scheduled')
            ->get(['search_listing.id', 'search_listing.listing_id']);

        $alreadySent = Listing::whereKey($rows->pluck('listing_id')->unique())->get()
            ->filter(fn (Listing $listing) => $listing->wasNotified())
            ->modelKeys();
        $alreadySentRows = $rows->whereIn('listing_id', $alreadySent)->pluck('id');
        DB::table('search_listing')->whereIn('id', $alreadySentRows)->update(['notify_skipped_reason' => 'already_sent']);

        $rowIds = $rows->whereNotIn('listing_id', $alreadySent)->pluck('id')->values()->all();
        $drops = NotifyGroups::pullPriceDrops($dealType);

        if (($rowIds !== [] || $drops !== []) && $notifier->canNotifyGroup($dealType)) {
            SendGroupDigestJob::dispatch($dealType, $rowIds, array_values($drops), NotifyGroups::periodLabel($dealType));
            $this->line("{$dealType->value}: podsumowanie kanału, ".count($rowIds).' ofert, '.count($drops).' obniżek');
        }

        // Termin mija także bez ofert, żeby podsumowanie nie przyszło o przypadkowej porze.
        NotifyGroups::markSent($dealType, $now);
    }
}
