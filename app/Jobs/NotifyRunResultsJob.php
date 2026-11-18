<?php

namespace App\Jobs;

use App\Enums\NotifyMode;
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

        // Oferta wysłana już z innego wyszukiwania: bez drugiej wiadomości i bez drugiej oceny AI.
        $alreadySent = Listing::whereKey($pendingIds)->get()
            ->filter(fn (Listing $listing) => $listing->wasNotified($search->id))
            ->modelKeys();

        if ($alreadySent !== []) {
            $this->skip($alreadySent, 'already_sent');
            $pendingIds = $pendingIds->diff($alreadySent)->values();
        }

        if ($pendingIds->isEmpty()) {
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

        // Ocena AI idzie zawsze (wyniki są w panelu), wysyłka tylko przy włączonych powiadomieniach.
        if (! $search->notify_enabled) {
            $this->skip($pendingIds->all(), 'notifications_off');

            return;
        }

        if (! $notifier->canNotify($search)) {
            $this->skip($pendingIds->all(), 'no_channel');

            return;
        }

        // Tryb zbiorczy: dobre oferty czekają na podsumowanie (notify:send-scheduled).
        if (! $this->firstRun && $search->notify_mode !== NotifyMode::Instant) {
            foreach ($this->belowThreshold($pendingIds) as $listingId => $below) {
                $this->skip([$listingId], $below ? 'below_threshold' : 'scheduled');
            }

            return;
        }

        if ($this->firstRun || $pendingIds->count() > config('notify.digest_threshold')) {
            SendDigestJob::dispatch($search, $pendingIds->all(), $this->firstRun);

            return;
        }

        $below = $this->belowThreshold($pendingIds);

        foreach (Listing::whereKey($pendingIds)->orderBy('first_seen_at')->get() as $listing) {
            if ($below[$listing->id]) {
                $this->skip([$listing->id], 'below_threshold');

                continue;
            }

            SendListingNotificationJob::dispatch($search, $listing);
        }
    }

    /**
     * listing_id => czy ocena jest poniżej progu. Bez oceny (błąd AI) oferta idzie dalej z dopiskiem.
     *
     * @return array<int, bool>
     */
    private function belowThreshold($listingIds): array
    {
        $scores = ListingAnalysis::where('search_id', $this->search->id)->whereIn('listing_id', $listingIds)->pluck('score', 'listing_id');

        return collect($listingIds)->mapWithKeys(fn ($id) => [
            $id => $scores->has($id) && $scores[$id] < $this->search->min_score_to_notify,
        ])->all();
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
