<?php

namespace App\Console\Commands;

use App\Models\Search;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('search:pause {id : ID wyszukiwania}')]
#[Description('Wstrzymuje wyszukiwanie')]
class SearchPause extends Command
{
    public function handle(): int
    {
        $search = Search::find($this->argument('id'));

        if (! $search) {
            $this->error('Nie ma wyszukiwania o takim ID.');

            return self::FAILURE;
        }

        $search->update(['is_active' => false]);

        $this->info("Wstrzymano wyszukiwanie #{$search->id}: {$search->name}");

        return self::SUCCESS;
    }
}
