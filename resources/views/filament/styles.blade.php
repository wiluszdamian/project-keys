{{-- Własne style panelu. Filament nie ma tu własnego motywu (bez Vite), więc zwykły CSS. --}}
<style>
    /* Karta oferty w tabeli na telefonie (ListingCard). */
    .pk-card { display: flex; gap: .75rem; align-items: flex-start; min-width: 0; white-space: normal; }
    .pk-card__img { flex: none; width: 5.5rem; height: 4.25rem; border-radius: .5rem; object-fit: cover; background: var(--gray-100); }
    .dark .pk-card__img { background: rgb(255 255 255 / .06); }
    .pk-card__body { display: flex; flex-direction: column; gap: .2rem; min-width: 0; font-size: .875rem; line-height: 1.35; }
    .pk-card__title { font-weight: 600; color: var(--gray-950); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .dark .pk-card__title { color: white; }
    .pk-card__muted { color: var(--gray-500); font-size: .8125rem; }
    .dark .pk-card__muted { color: var(--gray-400); }
    .pk-card__price { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem; color: var(--gray-950); }
    .dark .pk-card__price { color: white; }
    .pk-card__score { font-size: .75rem; font-weight: 600; padding: .05rem .4rem; border-radius: .375rem; }
    .pk-card__score--good { background: var(--success-50); color: var(--success-700); }
    .pk-card__score--mid { background: var(--warning-50); color: var(--warning-700); }
    .pk-card__score--bad { background: var(--danger-50); color: var(--danger-700); }
    .pk-card__score--none { background: var(--gray-100); color: var(--gray-600); }
    .dark .pk-card__score--good { background: rgb(34 197 94 / .15); color: var(--success-400); }
    .dark .pk-card__score--mid { background: rgb(245 158 11 / .15); color: var(--warning-400); }
    .dark .pk-card__score--bad { background: rgb(239 68 68 / .15); color: var(--danger-400); }
    .pk-card__meta { display: flex; flex-wrap: wrap; align-items: center; gap: .375rem; margin-top: .15rem; color: var(--gray-500); font-size: .75rem; }

    /* Kolumna z tytułem oferty na komputerze: szersza, żeby tytuł nie łamał się co słowo. */
    .pk-col-title { min-width: 13rem; max-width: 22rem; }
    .pk-col-price { min-width: 6.5rem; max-width: 8.5rem; }

    /* Szerokie tabele w widokach (raport rynku): przewijanie w poziomie zamiast ucinania. */
    .pk-scroll-x { overflow-x: auto; -webkit-overflow-scrolling: touch; }

    /* Tabele w podglądzie oferty (transakcje, dojazd, historia cen). */
    .pk-rows { width: 100%; border-collapse: collapse; font-size: .875rem; white-space: nowrap; }
    .pk-rows th { text-align: left; font-weight: 500; color: var(--gray-500); padding: .375rem .75rem .375rem 0; border-bottom: 1px solid var(--gray-200); }
    .pk-rows td { padding: .5rem .75rem .5rem 0; border-bottom: 1px solid var(--gray-100); color: var(--gray-950); }
    .pk-rows tr:last-child td { border-bottom: 0; }
    .dark .pk-rows th { color: var(--gray-400); border-color: rgb(255 255 255 / .1); }
    .dark .pk-rows td { color: white; border-color: rgb(255 255 255 / .05); }
    .pk-rows__link { color: var(--primary-600); font-weight: 500; }
    .dark .pk-rows__link { color: var(--primary-400); }

    @media (max-width: 767px) {
        /* Mniej pustego miejsca po bokach na telefonie. */
        .fi-main { padding-left: .75rem; padding-right: .75rem; }
        .fi-header-heading { font-size: 1.375rem; line-height: 1.75rem; }

        /* Liczby na panelu głównym: dwa kafelki w rzędzie, mniejsza czcionka. */
        .fi-wi-stats-overview-stat { padding: 1rem; }
        .fi-wi-stats-overview-stat-value { font-size: 1.5rem; line-height: 2rem; }
    }

    /* Tabele z kartą oferty (do xl, jak ListingCard::BREAKPOINT): bez pola zaznaczania i ikon akcji, cała karta prowadzi do oferty. */
    @media (max-width: 1279px) {
        .fi-ta-table:has(.pk-card) .fi-ta-selection-cell,
        .fi-ta-table:has(.pk-card) .fi-ta-actions-header-cell,
        .fi-ta-table:has(.pk-card) td:has(> .fi-ta-actions),
        .fi-ta:has(.pk-card) .fi-ta-header-toolbar > .fi-ta-actions { display: none; }
        .pk-card__title { font-size: .9375rem; }
    }
</style>
