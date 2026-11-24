<?php

namespace App\Services;

use App\Enums\DealType;
use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\ScrapeRun;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Jak długo oferta wisi na portalach i po ilu dniach zwykle schodzą podobne.
 * Początek: data dodania z portalu (published_at), a gdy jej nie ma, pierwsze zobaczenie u nas.
 * Oferta, którą znaleźliśmy w pierwszym przebiegu portalu, mogła wisieć dłużej: wtedy liczba dni to minimum.
 */
class TimeOnMarket
{
    /**
     * Data dodania ogłoszenia z danych portalu albo null.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function publishedAt(string $portal, array $raw): ?Carbon
    {
        $candidates = match ($portal) {
            'otodom' => [$raw['createdAtFirst'] ?? null, $raw['dateCreated'] ?? null],
            'olx' => [$raw['created_time'] ?? null],
            'morizon' => [$raw['added_at'] ?? null],
            'domiporta' => [$raw['date_posted'] ?? null],
            'rzeszowiak' => [$raw['added'] ?? null],
            default => [],
        };

        foreach ($candidates as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                $date = Carbon::parse($value)->setTimezone(config('app.timezone'));
            } catch (Throwable) {
                continue;
            }

            // Otodom czasem podaje zaślepkę "1999-02-29".
            if ($date->year >= 2005 && $date->lte(now()->addDay())) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @return array{since: ?CarbonInterface, days: ?int, known: bool, gone: bool, typical: array{median: int, p75: int, count: int, scope: string}|null, threshold: int, long: bool}
     */
    public static function assess(Listing $listing): array
    {
        $measure = self::measure($listing);
        $typical = self::typical($listing->deal_type, $listing->rooms);
        $threshold = $typical ? max($typical['p75'], (int) config('scrapers.time_on_market.min_long_days')) : self::defaultThreshold($listing->deal_type);

        return $measure + [
            'typical' => $typical,
            'threshold' => $threshold,
            // Przy nieznanym początku liczba dni to minimum, więc "długo" nadal jest pewne.
            'long' => ! $measure['gone'] && $measure['days'] !== null && $measure['days'] >= $threshold,
        ];
    }

    /**
     * Początek, liczba dni (do zdjęcia z ostatniego portalu albo do dziś) i czy początek jest pewny.
     *
     * @return array{since: ?CarbonInterface, days: ?int, known: bool, gone: bool}
     */
    public static function measure(Listing $listing): array
    {
        $group = self::group($listing);
        [$since, $known] = self::start($group);
        $gone = $group->every(fn (Listing $item) => $item->inactive_at !== null);
        $end = $gone ? $group->max('inactive_at') : now();

        return [
            'since' => $since,
            'days' => $since ? (int) floor($since->diffInDays($end, absolute: true)) : null,
            'known' => $known,
            'gone' => $gone,
        ];
    }

    /**
     * "Na rynku 45 dni (od 10.10.2026). Podobne schodzą zwykle po 12 dniach. Wisi długo: jest miejsce na negocjację ceny."
     */
    public static function describe(Listing $listing): ?string
    {
        $assessment = self::assess($listing);

        if ($assessment['days'] === null) {
            return null;
        }

        $days = self::days($assessment['days']);
        $text = $assessment['gone']
            ? "Była na rynku {$days}"
            : 'Na rynku '.($assessment['known'] ? '' : 'co najmniej ').$days;
        $text .= ' (od '.$assessment['since']->format('d.m.Y').').';

        if ($typical = $assessment['typical']) {
            $text .= " Podobne oferty ({$typical['scope']}) schodzą zwykle po ".self::days($typical['median']).'.';
        }

        if ($assessment['long']) {
            $text .= ' Wisi długo: jest miejsce na negocjację ceny.';
        }

        return $text;
    }

    /**
     * Krótko do listy: "45 dni" albo null, gdy oferta nie wisi długo.
     */
    public static function longBadge(Listing $listing): ?string
    {
        $assessment = self::assess($listing);

        return $assessment['long'] ? 'Wisi '.self::days($assessment['days']) : null;
    }

    /**
     * Po ilu dniach schodzą zdjęte oferty: ten typ i liczba pokoi, a przy małej próbie cały typ.
     * Tylko oferty ze znanym początkiem, z ostatnich kilku miesięcy.
     *
     * @return array{median: int, p75: int, count: int, scope: string}|null
     */
    public static function typical(DealType $dealType, ?int $rooms): ?array
    {
        $bucket = $rooms === null ? null : min($rooms, 4);

        return Cache::remember("time-on-market:{$dealType->value}:".($bucket ?? 'all'), now()->addHours(6), function () use ($dealType, $bucket) {
            $all = self::goneDurations($dealType);
            $minSample = (int) config('scrapers.time_on_market.min_sample');
            $sameRooms = $bucket === null ? collect() : $all->where('rooms', $bucket);

            [$rows, $scope] = $sameRooms->count() >= $minSample
                ? [$sameRooms, $bucket >= 4 ? '4+ pok.' : "{$bucket} pok."]
                : [$all, $dealType === DealType::Rent ? 'wynajem' : 'sprzedaż'];

            if ($rows->count() < $minSample) {
                return null;
            }

            $days = $rows->pluck('days')->sort()->values();

            return [
                'median' => (int) round((float) $days->median()),
                'p75' => (int) $days[(int) floor(($days->count() - 1) * 0.75)],
                'count' => $days->count(),
                'scope' => $scope,
            ];
        });
    }

    /**
     * Do raportu tygodniowego: ile ofert zeszło w tygodniu, po ilu dniach, i które aktywne wiszą najdłużej.
     *
     * @return array{gone: int, known: int, median_days: ?int, by_rooms: list<array{rooms: string, count: int, median_days: int}>, longest: list<array<string, mixed>>}
     */
    public static function weekly(DealType $dealType, CarbonInterface $from, CarbonInterface $to): array
    {
        $gone = self::goneQuery($dealType)
            ->whereBetween('inactive_at', [$from, $to])
            ->with('duplicates:id,duplicate_of_id,portal,first_seen_at,published_at,inactive_at')
            ->get()
            ->map(fn (Listing $listing) => ['rooms' => $listing->rooms === null ? null : min($listing->rooms, 4)] + self::measure($listing));

        $known = $gone->where('known', true);

        $longest = Listing::query()
            ->where('deal_type', $dealType)
            ->whereNull('duplicate_of_id')
            ->available()
            ->whereHas('searches')
            ->where(fn (Builder $query) => $query->whereNull('status')->orWhere('status', '!=', ListingStatus::Rejected->value))
            ->with('duplicates:id,duplicate_of_id,portal,first_seen_at,published_at,inactive_at')
            ->get()
            ->map(fn (Listing $listing) => ['listing' => $listing] + self::assess($listing))
            ->filter(fn (array $row) => $row['long'])
            ->sortByDesc('days')
            ->take(5)
            ->map(fn (array $row) => [
                'id' => $row['listing']->id,
                'title' => $row['listing']->title,
                'url' => $row['listing']->url,
                'price' => $row['listing']->price,
                'days' => $row['days'],
                'known' => $row['known'],
            ])
            ->values()
            ->all();

        return [
            'gone' => $gone->count(),
            'known' => $known->count(),
            'median_days' => $known->isEmpty() ? null : (int) round((float) $known->pluck('days')->median()),
            'by_rooms' => $known->whereNotNull('rooms')->groupBy('rooms')->sortKeys()
                ->map(fn (Collection $rows, int $rooms) => [
                    'rooms' => $rooms >= 4 ? '4+' : (string) $rooms,
                    'count' => $rows->count(),
                    'median_days' => (int) round((float) $rows->pluck('days')->median()),
                ])->values()->all(),
            'longest' => $longest,
        ];
    }

    public static function days(int $days): string
    {
        return $days.' '.match (true) {
            $days === 1 => 'dzień',
            default => 'dni',
        };
    }

    /**
     * Ta oferta i jej kopie z innych portali (od oryginału).
     *
     * @return Collection<int, Listing>
     */
    private static function group(Listing $listing): Collection
    {
        $main = $listing->duplicate_of_id ? ($listing->originalListing ?? $listing) : $listing;

        return collect([$main])->merge($main->duplicates)->unique('id')->values();
    }

    /**
     * Najwcześniejszy początek w grupie i czy jest pewny (data z portalu albo oferta pojawiła się po pierwszym przebiegu portalu).
     *
     * @param  Collection<int, Listing>  $group
     * @return array{0: ?CarbonInterface, 1: bool}
     */
    private static function start(Collection $group): array
    {
        $since = null;
        $known = false;
        $tracking = self::trackingStarts();

        foreach ($group as $listing) {
            if ($listing->published_at && ($since === null || $listing->published_at->lt($since))) {
                [$since, $known] = [$listing->published_at, true];
            }

            if ($listing->first_seen_at && ($since === null || $listing->first_seen_at->lt($since))) {
                $start = $tracking[$listing->portal] ?? null;
                [$since, $known] = [$listing->first_seen_at, $start !== null && $listing->first_seen_at->gt(Carbon::parse($start)->addDay())];
            }
        }

        return [$since, $known];
    }

    /**
     * Pierwszy przebieg każdego portalu: oferty znalezione wtedy mogły wisieć już wcześniej.
     *
     * @return array<string, string>
     */
    private static function trackingStarts(): array
    {
        return Cache::remember('time-on-market:tracking-starts', now()->addHours(6), fn () => ScrapeRun::query()
            ->groupBy('portal')
            ->selectRaw('portal, min(started_at) as started_at')
            ->pluck('started_at', 'portal')
            ->map(fn ($value) => (string) $value)
            ->all());
    }

    /**
     * @return Collection<int, array{rooms: ?int, days: int}>
     */
    private static function goneDurations(DealType $dealType): Collection
    {
        return self::goneQuery($dealType)
            ->where('inactive_at', '>=', now()->subDays((int) config('scrapers.time_on_market.lookback_days')))
            ->with('duplicates:id,duplicate_of_id,portal,first_seen_at,published_at,inactive_at')
            ->get(['id', 'portal', 'deal_type', 'rooms', 'first_seen_at', 'published_at', 'inactive_at', 'duplicate_of_id'])
            ->map(fn (Listing $listing) => ['rooms' => $listing->rooms === null ? null : min($listing->rooms, 4)] + self::measure($listing))
            ->filter(fn (array $row) => $row['known'] && $row['days'] !== null)
            ->map(fn (array $row) => ['rooms' => $row['rooms'], 'days' => $row['days']])
            ->values();
    }

    private static function goneQuery(DealType $dealType): Builder
    {
        return Listing::query()->where('deal_type', $dealType)->whereNull('duplicate_of_id')->gone();
    }

    private static function defaultThreshold(DealType $dealType): int
    {
        return (int) config('scrapers.time_on_market.long_days.'.$dealType->value);
    }
}
