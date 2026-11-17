<?php

namespace App\Jobs;

use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\Search;
use App\Notifications\Notifier;
use App\Services\ListingScorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Po przebiegu wyszukiwania. Najpierw ocena AI nowych ofert (batch AnalyzeListingJob),
 * potem pojedyncze wiadomości dla ofert z oceną od min_score_to_notify
 * albo jedno podsumowanie, gdy to pierwszy przebieg lub nowych ofert jest więcej niż NOTIFY_DIGEST_THRESHOLD.
 */
class NotifyRunResultsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Search $search,
        public string $runStartedAt,
        public bool $firstRun,
        public bool $analyzed = false,
    ) {
        $this->onQueue('notify');
    }

    public function handle(Notifier $notifier): void
    {
        $search = $this->search;
        $pendingIds = $this->pendingIds();

        if ($pendingIds->isEmpty()) {
            return;
        }

        if (! $notifier->canNotify($search)) {
            $this->skip($pendingIds->all(), 'no_channel');

            return;
        }

        if (! $this->analyzed && ListingScorer::isEnabled()) {
            $jobs = Listing::whereKey($pendingIds)->orderBy('id')->get()
                ->map(fn (Listing $listing) => new AnalyzeListingJob($search, $listing))
                ->all();
            $searchId = $search->id;
            $runStartedAt = $this->runStartedAt;
            $firstRun = $this->firstRun;

            // Ocena równolegle (kilka workerów kolejki ai), wysyłka po ocenie wszystkich ofert.
            Bus::batch($jobs)
                ->name("ai:search:{$searchId}")
                ->onQueue('ai')
                ->allowFailures()
                ->finally(function () use ($searchId, $runStartedAt, $firstRun) {
                    if ($search = Search::find($searchId)) {
                        NotifyRunResultsJob::dispatch($search, $runStartedAt, $firstRun, analyzed: true);
                    }
                })
                ->dispatch();

            return;
        }

        if ($this->firstRun || $pendingIds->count() > config('notify.digest_threshold')) {
            SendDigestJob::dispatch($search, $pendingIds->all(), $this->firstRun);

            return;
        }

        $analyses = ListingAnalysis::where('search_id', $search->id)->whereIn('listing_id', $pendingIds)->get()->keyBy('listing_id');

        foreach (Listing::whereKey($pendingIds)->orderBy('first_seen_at')->get() as $listing) {
            $analysis = $analyses->get($listing->id);

            // Bez oceny (błąd AI) oferta idzie dalej z dopiskiem. Z oceną poniżej progu: pomijamy.
            if ($analysis && $analysis->score < $search->min_score_to_notify) {
                $this->skip([$listing->id], 'below_threshold');

                continue;
            }

            SendListingNotificationJob::dispatch($search, $listing);
        }
    }

    private function pendingIds()
    {
        return DB::table('search_listing')
            ->where('search_id', $this->search->id)
            ->where('matched_at', '>=', Carbon::parse($this->runStartedAt))
            ->whereNull('notified_at')
            ->whereNull('notify_skipped_reason')
            ->pluck('listing_id');
    }

    private function skip(array $listingIds, string $reason): void
    {
        DB::table('search_listing')
            ->where('search_id', $this->search->id)
            ->whereIn('listing_id', $listingIds)
            ->update(['notify_skipped_reason' => $reason]);
    }
}
