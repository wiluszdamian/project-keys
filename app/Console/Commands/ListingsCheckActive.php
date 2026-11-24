<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Scrapers\Exceptions\PortalBlockedException;
use App\Scrapers\Exceptions\ScraperException;
use App\Scrapers\HttpFetcher;
use App\Services\ListingAvailability;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

#[Signature('listings:check-active {--limit= : Ile stron ogłoszeń sprawdzić (domyślnie scrapers.availability.per_run)} {--id=* : Tylko te oferty}')]
#[Description('Sprawdza, czy ogłoszenia, których nie było ostatnio w wynikach, są jeszcze na portalu, i oznacza zdjęte jako nieaktywne')]
class ListingsCheckActive extends Command
{
    public function handle(ListingAvailability $availability, HttpFetcher $fetcher): int
    {
        $limit = (int) ($this->option('limit') ?: config('scrapers.availability.per_run'));
        $listings = $this->option('id') !== []
            ? Listing::whereKey($this->option('id'))->get()
            : $this->due($limit, $fetcher);

        $inactive = 0;
        $errors = 0;

        foreach ($listings as $listing) {
            try {
                $reason = $availability->check($listing);
            } catch (PortalBlockedException $e) {
                // Portal wstrzymany: reszta jego ofert poczeka na następny przebieg.
                $this->warn($e->getMessage());
                $errors++;

                continue;
            } catch (ScraperException $e) {
                $listing->forceFill(['availability_checked_at' => now()])->save();
                $this->warn("#{$listing->id}: {$e->getMessage()}");
                $errors++;

                continue;
            }

            if ($reason !== null) {
                $inactive++;
                $this->line("#{$listing->id} {$listing->portal}: {$reason}");
            }
        }

        $this->info("Sprawdzone: {$listings->count()}, nieaktywne: {$inactive}, błędy: {$errors}.");

        return self::SUCCESS;
    }

    /**
     * Oferty do sprawdzenia: aktywne, dawno niewidziane w wynikach i dawno niesprawdzane, z wyszukiwań
     * albo ze statusem. Na zmianę z różnych portali, żeby przerwy między zapytaniami do jednego portalu
     * nie blokowały reszty.
     *
     * @return Collection<int, Listing>
     */
    private function due(int $limit, HttpFetcher $fetcher): Collection
    {
        $config = config('scrapers.availability');

        return Listing::query()
            ->whereNull('inactive_at')
            ->where('last_seen_at', '<', now()->subHours($config['unseen_hours']))
            ->where(fn (Builder $query) => $query
                ->whereNull('availability_checked_at')
                ->orWhere('availability_checked_at', '<', now()->subHours($config['recheck_hours'])))
            ->where(fn (Builder $query) => $query
                ->whereHas('searches')
                ->orWhereNotNull('status')
                ->orWhereHas('originalListing', fn (Builder $query) => $query->whereHas('searches')->orWhereNotNull('status')))
            ->orderByRaw('availability_checked_at nulls first')
            ->orderBy('last_seen_at')
            ->limit($limit * 3)
            ->get()
            ->reject(fn (Listing $listing) => $fetcher->isPaused($listing->portal))
            ->groupBy('portal')
            ->map(fn (Collection $group) => $group->values())
            ->pipe(fn (Collection $groups) => $this->interleave($groups))
            ->take($limit)
            ->values();
    }

    /**
     * [a1, a2], [b1] -> [a1, b1, a2]
     *
     * @param  Collection<string, Collection<int, Listing>>  $groups
     */
    private function interleave(Collection $groups): Collection
    {
        $result = collect();
        $max = $groups->max(fn (Collection $group) => $group->count()) ?? 0;

        for ($i = 0; $i < $max; $i++) {
            foreach ($groups as $group) {
                if (isset($group[$i])) {
                    $result->push($group[$i]);
                }
            }
        }

        return $result;
    }
}
