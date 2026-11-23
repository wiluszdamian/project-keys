<?php

namespace App\Console\Commands;

use App\Models\Search;
use App\Scrapers\Data\ListingData;
use App\Scrapers\ScraperRegistry;
use App\Services\ListingMatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('scraper:probe {portal : otodom, olx, rzeszowiak, morizon, gratka, nieruchomosci_online, domiporta, adresowo, stoprocent, rzeszowskie albo pierwsze_pietro} {search : ID wyszukiwania} {--pages=1 : Ile stron pobrać}')]
#[Description('Pobiera oferty z portalu na żywo i wypisuje je. Nic nie zapisuje.')]
class ScraperProbe extends Command
{
    public function handle(ScraperRegistry $registry, ListingMatcher $matcher): int
    {
        $search = Search::find($this->argument('search'));

        if (! $search) {
            $this->error('Nie ma wyszukiwania o takim ID.');

            return self::FAILURE;
        }

        $scraper = $registry->get($this->argument('portal'));
        config(['scrapers.max_pages' => max(1, (int) $this->option('pages'))]);

        $this->line('Adres: '.$scraper->buildSearchUrl($search));

        $result = $scraper->fetchListings($search);
        $matching = array_filter($result->listings, fn (ListingData $listing) => $matcher->matches($search, $listing));

        $this->table(
            ['ID', 'Cena', 'Czynsz', 'm2', 'Pokoje', 'Piętro', 'Dzielnica', 'Prywatne', 'Tytuł'],
            array_map(fn (ListingData $listing) => [
                $listing->externalId,
                $listing->price,
                $listing->rentExtra,
                $listing->area,
                $listing->rooms,
                $listing->floor,
                $listing->district,
                match ($listing->isPrivateOwner) {
                    true => 'tak',
                    false => 'nie',
                    null => '?',
                },
                Str::limit($listing->title, 50),
            ], $matching),
        );

        $this->info(sprintf(
            'Stron: %d, ogłoszeń: %d, pasuje do filtrów: %d. Nic nie zapisano.',
            $result->pagesFetched,
            count($result->listings),
            count($matching),
        ));

        return self::SUCCESS;
    }
}
