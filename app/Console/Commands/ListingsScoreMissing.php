<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeListingJob;
use App\Models\Listing;
use App\Models\Search;
use App\Services\ListingScorer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('listings:score-missing {--days=3 : Tylko oferty widziane na portalu w tylu ostatnich dniach} {--dry-run : Tylko policz}')]
#[Description('Zleca ocenę AI ofertom przypisanym do aktywnych wyszukiwań, które jej nie mają (np. sprzed włączenia AI albo po awarii).')]
class ListingsScoreMissing extends Command
{
    public function handle(): int
    {
        if (! ListingScorer::isEnabled()) {
            $this->error('Ocena AI jest wyłączona albo brak klucza.');

            return self::FAILURE;
        }

        $total = 0;

        foreach (Search::active()->get() as $search) {
            $listings = $search->listings()
                ->whereNull('duplicate_of_id')
                ->whereNull('inactive_at')
                ->where('last_seen_at', '>=', now()->subDays((int) $this->option('days')))
                ->whereDoesntHave('analyses', fn (Builder $query) => $query->where('search_id', $search->id))
                ->get();

            if (! $this->option('dry-run')) {
                $listings->each(fn (Listing $listing) => AnalyzeListingJob::dispatch($search, $listing));
            }

            $total += $listings->count();
            $this->info("#{$search->id} {$search->name}: {$listings->count()} ".($this->option('dry-run') ? 'do oceny' : 'w kolejce'));
        }

        $this->info("Razem: {$total}.");

        return self::SUCCESS;
    }
}
