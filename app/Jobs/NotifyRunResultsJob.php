<?php

namespace App\Jobs;

use App\Models\Listing;
use App\Models\Search;
use App\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Po przebiegu wyszukiwania: pojedyncze wiadomości albo jedno podsumowanie,
 * gdy to pierwszy przebieg lub nowych ofert jest więcej niż NOTIFY_DIGEST_THRESHOLD.
 */
class NotifyRunResultsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Search $search,
        public string $runStartedAt,
        public bool $firstRun,
    ) {
        $this->onQueue('notify');
    }

    public function handle(Notifier $notifier): void
    {
        $search = $this->search;

        $pendingIds = DB::table('search_listing')
            ->where('search_id', $search->id)
            ->where('matched_at', '>=', Carbon::parse($this->runStartedAt))
            ->whereNull('notified_at')
            ->whereNull('notify_skipped_reason')
            ->pluck('listing_id');

        if ($pendingIds->isEmpty()) {
            return;
        }

        if (! $notifier->canNotify($search)) {
            $this->skip($pendingIds->all(), 'no_channel');

            return;
        }

        if ($this->firstRun || $pendingIds->count() > config('notify.digest_threshold')) {
            SendDigestJob::dispatch($search, $pendingIds->all(), $this->firstRun);

            return;
        }

        foreach (Listing::whereKey($pendingIds)->orderBy('first_seen_at')->get() as $listing) {
            SendListingNotificationJob::dispatch($search, $listing);
        }
    }

    private function skip(array $listingIds, string $reason): void
    {
        DB::table('search_listing')
            ->where('search_id', $this->search->id)
            ->whereIn('listing_id', $listingIds)
            ->update(['notify_skipped_reason' => $reason]);
    }
}
