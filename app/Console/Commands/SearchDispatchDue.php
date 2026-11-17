<?php

namespace App\Console\Commands;

use App\Jobs\RunSearchJob;
use App\Models\Search;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('search:dispatch-due')]
#[Description('Dodaje do kolejki aktywne wyszukiwania, które czekają dłużej niż SCRAPE_INTERVAL_MINUTES')]
class SearchDispatchDue extends Command
{
    public function handle(): int
    {
        // 30 s zapasu, żeby przebieg co 20 minut nie przesuwał się o minutę przy każdym uruchomieniu.
        $dueBefore = now()->subMinutes(config('scrapers.interval_minutes'))->addSeconds(30);

        $searches = Search::active()
            ->where(fn ($query) => $query->whereNull('last_run_at')->orWhere('last_run_at', '<=', $dueBefore))
            ->get();

        foreach ($searches as $search) {
            RunSearchJob::dispatch($search);
        }

        $this->info("Dodano do kolejki: {$searches->count()}");

        return self::SUCCESS;
    }
}
