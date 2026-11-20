<?php

namespace App\Filament\Widgets;

use App\Enums\ScrapeRunStatus;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\ScrapeRun;
use App\Models\Search;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    /**
     * Na telefonie dwa kafelki w rzędzie zamiast pięciu wysokich kart jedna pod drugą.
     */
    protected function getColumns(): int|array|null
    {
        return ['default' => 2, 'md' => 3, 'xl' => 5];
    }

    protected function getStats(): array
    {
        $since = now()->subDay();
        $new = Listing::where('first_seen_at', '>=', $since)->whereNull('duplicate_of_id')->count();
        $good = DB::table('search_listing')
            ->join('searches', 'searches.id', '=', 'search_listing.search_id')
            ->join('listing_analyses', fn ($join) => $join->on('listing_analyses.listing_id', '=', 'search_listing.listing_id')
                ->on('listing_analyses.search_id', '=', 'search_listing.search_id'))
            ->where('search_listing.matched_at', '>=', $since)
            ->whereColumn('listing_analyses.score', '>=', 'searches.min_score_to_notify')
            ->distinct()
            ->count('search_listing.listing_id');

        $lastOk = ScrapeRun::where('status', ScrapeRunStatus::Ok)->max('finished_at');
        $problems = ScrapeRun::where('started_at', '>=', now()->subHours(3))
            ->whereIn('status', [ScrapeRunStatus::Blocked, ScrapeRunStatus::Error])
            ->count();

        $tokens = ListingAnalysis::where('updated_at', '>=', now()->startOfDay())
            ->selectRaw('count(*) as calls, coalesce(sum(input_tokens), 0) as input, coalesce(sum(output_tokens), 0) as output')
            ->first();

        return [
            Stat::make('Nowe (24 h)', $new)
                ->description('ofert bez duplikatów'),
            Stat::make('Dobre (24 h)', $good)
                ->description('ocena od progu')
                ->color('success'),
            Stat::make('Ostatni przebieg', $lastOk ? now()->parse($lastOk)->diffForHumans(short: true) : 'brak')
                ->description($problems ? "{$problems} blokad lub błędów w 3 h" : 'bez problemów w 3 h')
                ->descriptionColor($problems ? 'danger' : 'success'),
            Stat::make('AI dzisiaj', $tokens->calls.' ocen')
                ->description(Number::format((int) $tokens->input, locale: 'pl').' wej. / '.Number::format((int) $tokens->output, locale: 'pl').' wyj. tokenów'),
            Stat::make('Aktywne wyszukiwania', Search::active()->count().' z '.Search::count()),
        ];
    }
}
