<?php

namespace App\Services;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\Search;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Decyzje osoby z panelu jako przykłady do oceny AI: oferty polubione (ulubiona, kontakt, oglądanie)
 * i odrzucone, z notatkami. Ten sam typ transakcji co wyszukiwanie, od najnowszych decyzji.
 */
class DecisionExamples
{
    public const LIKED = [ListingStatus::Favorite, ListingStatus::Contacted, ListingStatus::Viewing];

    /**
     * Linie do promptu. Pusta lista, gdy nauka jest wyłączona albo nie ma decyzji.
     *
     * @return list<string>
     */
    public function lines(Search $search, Listing $except): array
    {
        if (! config('scoring.learn_from_decisions')) {
            return [];
        }

        $liked = $this->examples($search, $except, self::LIKED);
        $rejected = $this->examples($search, $except, [ListingStatus::Rejected]);

        if ($liked->isEmpty() && $rejected->isEmpty()) {
            return [];
        }

        return [
            '## Decyzje osoby w panelu (przykłady, czego szuka)',
            ...($liked->isEmpty() ? ['- polubione: brak'] : $liked->map($this->line(...))->all()),
            ...($rejected->isEmpty() ? ['- odrzucone: brak'] : $rejected->map($this->line(...))->all()),
            '',
        ];
    }

    /**
     * @param  list<ListingStatus>  $statuses
     * @return Collection<int, Listing>
     */
    private function examples(Search $search, Listing $except, array $statuses): Collection
    {
        return Listing::query()
            ->whereIn('status', array_map(fn (ListingStatus $status) => $status->value, $statuses))
            ->where('deal_type', $search->deal_type)
            ->whereKeyNot($except->id)
            ->whereNull('duplicate_of_id')
            ->latest('updated_at')
            ->limit(max(1, (int) config('scoring.decision_examples')))
            ->get();
    }

    private function line(Listing $listing): string
    {
        $facts = collect([
            $listing->price ? number_format($listing->price, 0, ',', ' ').' zł' : null,
            $listing->area ? rtrim(rtrim(number_format((float) $listing->area, 1, ',', ''), '0'), ',').' m²' : null,
            $listing->rooms ? "{$listing->rooms} pok." : null,
            $listing->floor !== null ? "piętro {$listing->floor}" : null,
            collect([$listing->district, $listing->city])->filter()->unique()->implode(', ') ?: null,
        ])->filter()->implode(', ');

        $note = trim((string) $listing->note);

        return '- '.mb_strtolower($listing->status->getLabel()).': '.Str::limit(Str::squish($listing->title), 90)
            .($facts !== '' ? " ({$facts})" : '')
            .($note !== '' ? '. Notatka osoby: '.Str::limit(Str::squish($note), 200) : '');
    }
}
