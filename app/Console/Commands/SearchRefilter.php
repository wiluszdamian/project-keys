<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\Search;
use App\Services\ListingMatcher;
use App\Services\ListingUpserter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('search:refilter {search* : ID wyszukiwań} {--dry-run : Tylko policz, nic nie zmieniaj}')]
#[Description('Sprawdza oferty przypisane do wyszukiwania z jego obecnymi filtrami i odłącza te, które już nie pasują.')]
class SearchRefilter extends Command
{
    public function handle(ListingMatcher $matcher, ListingUpserter $upserter): int
    {
        foreach ($this->argument('search') as $id) {
            $search = Search::find($id);

            if (! $search) {
                $this->error("Nie ma wyszukiwania #{$id}.");

                return self::FAILURE;
            }

            $removed = 0;
            $total = 0;

            $search->listings()->chunkById(200, function ($listings) use ($search, $matcher, $upserter, &$removed, &$total) {
                foreach ($listings as $listing) {
                    /** @var Listing $listing */
                    $total++;

                    if ($matcher->matchesListing($search, $listing)) {
                        continue;
                    }

                    $removed++;

                    if (! $this->option('dry-run')) {
                        $upserter->detach($search, $listing);
                    }
                }
            }, 'listings.id', 'id');

            $verb = $this->option('dry-run') ? 'do odłączenia' : 'odłączone';
            $this->info("#{$search->id} {$search->name}: {$total} ofert, {$removed} {$verb}.");
        }

        return self::SUCCESS;
    }
}
