<?php

namespace App\Console\Commands;

use App\Enums\NotifyMode;
use App\Jobs\SendDigestJob;
use App\Models\Listing;
use App\Models\Search;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('notify:send-scheduled')]
#[Description('Wysyła zebrane oferty wyszukiwań z powiadomieniami co kilka godzin albo raz dziennie')]
class NotifySendScheduled extends Command
{
    public function handle(): int
    {
        $now = now();

        foreach (Search::where('notify_enabled', true)->get() as $search) {
            if (! $search->scheduledNotifyDue($now)) {
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
}
