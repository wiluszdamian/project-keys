<?php

namespace App\Console\Commands;

use App\Jobs\RunSearchJob;
use App\Models\Search;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('search:run {id : ID wyszukiwania}')]
#[Description('Uruchamia przebieg wyszukiwania teraz (przez kolejkę)')]
class SearchRunNow extends Command
{
    public function handle(): int
    {
        $search = Search::find($this->argument('id'));

        if (! $search) {
            $this->error('Nie ma wyszukiwania o takim ID.');

            return self::FAILURE;
        }

        RunSearchJob::dispatch($search);

        $this->info("Przebieg wyszukiwania #{$search->id} dodany do kolejki. Wynik: php artisan search:list albo GET /api/scrape-runs");

        return self::SUCCESS;
    }
}
