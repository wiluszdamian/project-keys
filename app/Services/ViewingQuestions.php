<?php

namespace App\Services;

use App\Enums\DealType;
use App\Models\Listing;

/**
 * Pytania na oglądanie: stała lista do sprawdzenia na miejscu, pytania o to, czego ogłoszenie nie mówi,
 * i propozycje AI (zapisane w viewing_questions przez SuggestViewingQuestionsJob).
 */
class ViewingQuestions
{
    private const RENT = [
        'Ile realnie wychodzą media zimą (prąd, gaz, ogrzewanie)? Poproś o ostatnie rachunki.',
        'Co dokładnie obejmuje czynsz administracyjny i czy rozliczenie wody bywa z dopłatą?',
        'Jakie są warunki zwrotu kaucji i w jakim terminie?',
        'Czy będzie protokół zdawczo-odbiorczy ze zdjęciami i stanem liczników?',
        'Kto płaci za drobne naprawy i awarie sprzętów?',
        'Czy można się zameldować i czy umowa jest zgłaszana do urzędu skarbowego?',
        'Okres wypowiedzenia i czy można zakończyć umowę wcześniej.',
        'Sprawdź: wilgoć i pleśń (narożniki, łazienka, za meblami), szczelność okien, ciśnienie wody.',
        'Sprawdź: zasięg telefonu, hałas z ulicy i od sąsiadów, działanie sprzętów AGD.',
    ];

    private const SALE = [
        'Numer księgi wieczystej: sprawdź właściciela, hipotekę i służebności (dział III i IV).',
        'Czy jest zadłużenie w spółdzielni lub wspólnocie? Poproś o zaświadczenie o braku zaległości.',
        'Kto jest zameldowany i czy lokal będzie wymeldowany przed aktem?',
        'Ile wynosi fundusz remontowy i czy są planowane remonty budynku (dach, elewacja, winda)?',
        'Stan instalacji elektrycznej, wodnej i gazowej: kiedy wymieniane?',
        'Termin wydania mieszkania i co zostaje w środku (meble, AGD).',
        'Czy miejsce postojowe lub komórka są w księdze wieczystej, czy osobno?',
        'Sprawdź: wilgoć i pleśń, stan okien i pionów, ściany nośne przy planowanych zmianach układu.',
        'Sprawdź: nasłonecznienie (strony świata), hałas, części wspólne i klatka schodowa.',
    ];

    /**
     * @return list<string>
     */
    public static function base(Listing $listing): array
    {
        return $listing->deal_type === DealType::Rent ? self::RENT : self::SALE;
    }

    /**
     * Pytania o to, czego ogłoszenie nie podaje, i o sygnały z oceny AI oraz wykrytego ryzyka.
     *
     * @return list<string>
     */
    public static function forListing(Listing $listing): array
    {
        $facts = ListingFacts::of($listing);
        $rent = $listing->deal_type === DealType::Rent;
        $questions = [];

        if ($rent) {
            $terms = LeaseTerms::of($listing);
            [$fee] = $listing->adminFee();

            $questions[] = $fee === null ? 'Ile wynosi czynsz administracyjny i co obejmuje?' : null;
            $questions[] = $terms['deposit'] === null ? 'Jaka jest kaucja?' : null;
            $questions[] = $terms['contract'] === null ? 'Jaka to umowa: zwykła czy najem okazjonalny (z oświadczeniem u notariusza)?' : null;
            $questions[] = $terms['contract'] === 'najem okazjonalny' ? 'Kto płaci za notariusza przy najmie okazjonalnym i czy trzeba wskazać adres zastępczy?' : null;
            $questions[] = $terms['min_period'] === null ? 'Na jak długo jest umowa i czy jest minimalny okres najmu?' : null;
            $questions[] = $terms['free_from'] === null ? 'Od kiedy można się wprowadzić?' : null;
            $questions[] = $terms['pets'] === null ? 'Czy można mieszkać ze zwierzęciem?' : null;
            $questions[] = $terms['commission'] === null && $listing->is_private_owner !== true ? 'Czy jest prowizja dla biura i ile wynosi?' : null;
        } else {
            $questions[] = $listing->rent_extra === null ? 'Ile wynosi czynsz do spółdzielni lub wspólnoty?' : null;
            $questions[] = $facts['free_from'] === null ? 'Kiedy mieszkanie może być wydane?' : null;
        }

        $questions[] = $facts['heating'] === null ? 'Jakie jest ogrzewanie i ile kosztuje miesięcznie zimą?' : null;
        $questions[] = $facts['build_year'] === null ? 'Z którego roku jest budynek?' : null;
        $questions[] = $facts['parking'] === null ? 'Gdzie można parkować i czy miejsce jest w cenie?' : null;
        $questions[] = $facts['storage'] === null ? 'Czy jest piwnica albo komórka lokatorska?' : null;
        $questions[] = $facts['elevator'] === null && self::floor($listing) >= 3 ? 'Czy w budynku jest winda?' : null;

        foreach ($listing->risk_flags ?? [] as $flag) {
            $questions[] = 'Wyjaśnij: '.self::lower((string) ($flag['message'] ?? ''));
        }

        foreach ($listing->analyses->pluck('red_flags')->flatten()->filter()->unique()->take(4) as $flag) {
            $questions[] = 'Dopytaj (uwaga z oceny AI): '.self::lower((string) $flag);
        }

        return array_values(array_unique(array_filter($questions)));
    }

    /**
     * Pytania od AI zapisane przy ofercie: [['question' => ..., 'why' => ...]].
     *
     * @return list<array{question: string, why: ?string}>
     */
    public static function fromAi(Listing $listing): array
    {
        return collect($listing->viewing_questions ?? [])
            ->filter(fn ($row) => is_array($row) && filled($row['question'] ?? null))
            ->map(fn (array $row) => ['question' => (string) $row['question'], 'why' => filled($row['why'] ?? null) ? (string) $row['why'] : null])
            ->values()
            ->all();
    }

    private static function floor(Listing $listing): int
    {
        return preg_match('/\d+/', (string) $listing->floor, $match) === 1 ? (int) $match[0] : 0;
    }

    private static function lower(string $text): string
    {
        $text = rtrim(trim($text), '.');

        return mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1).'.';
    }
}
