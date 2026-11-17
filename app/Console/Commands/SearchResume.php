<?php

namespace App\Console\Commands;

use App\Models\Search;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('search:resume {id : ID wyszukiwania}')]
#[Description('Wznawia wyszukiwanie')]
class SearchResume extends Command
{
    public function handle(): int
    {
        $search = Search::find($this->argument('id'));

        if (! $search) {
            $this->error('Nie ma wyszukiwania o takim ID.');

            return self::FAILURE;
        }

        $search->update(['is_active' => true]);

        $this->info("Wznowiono wyszukiwanie #{$search->id}: {$search->name}");

        return self::SUCCESS;
    }
}
