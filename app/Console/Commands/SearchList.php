<?php

namespace App\Console\Commands;

use App\Models\Search;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('search:list')]
#[Description('Lista wyszukiwań')]
class SearchList extends Command
{
    public function handle(): int
    {
        $searches = Search::query()->withCount('listings')->orderBy('id')->get();

        if ($searches->isEmpty()) {
            $this->info('Brak wyszukiwań. Dodaj pierwsze komendą search:create.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Nazwa', 'Typ', 'Miasto', 'Cena', 'Portale', 'Aktywne', 'Ofert', 'Ostatni przebieg'],
            $searches->map(fn (Search $search) => [
                $search->id,
                $search->name,
                $search->deal_type->value,
                $search->city,
                ($search->price_min ?? '-').' - '.($search->price_max ?? '-'),
                implode(', ', $search->portals),
                $search->is_active ? 'tak' : 'nie',
                $search->listings_count,
                $search->last_run_at?->format('Y-m-d H:i') ?? '-',
            ]),
        );

        return self::SUCCESS;
    }
}
