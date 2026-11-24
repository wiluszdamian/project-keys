<?php

use Illuminate\Support\Facades\Schedule;

// Co minutę sprawdza, które wyszukiwania czekają dłużej niż SCRAPE_INTERVAL_MINUTES.
Schedule::command('search:dispatch-due')->everyMinute()->withoutOverlapping();

// Podsumowania wyszukiwań z powiadomieniami co kilka godzin albo raz dziennie.
Schedule::command('notify:send-scheduled')->everyMinute()->withoutOverlapping();

// Współrzędne, dojazdy, zdjęcia i ryzyko dla ofert, które ich jeszcze nie mają (porcjami).
Schedule::command('listings:enrich-missing')->everyTenMinutes()->withoutOverlapping()->runInBackground();

// Raport rynku z ostatnich 7 dni: każda sobota o 20:00 (strefa APP_TIMEZONE).
Schedule::command('reports:weekly-market')->weeklyOn(6, '20:00')->when(fn () => (bool) config('notify.weekly_report'));

// Ceny transakcyjne z RCN: pobranie i porównanie ofert sprzedaży, co niedzielę o 4:30.
Schedule::command('rcn:import')->weeklyOn(0, '04:30')->withoutOverlapping()->runInBackground()->when(fn () => (bool) config('rcn.enabled'));

// Sprzątanie kolejki: stare batche i nieudane joby.
Schedule::command('queue:prune-batches --hours=48')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();

// Czy ogłoszenia, których nie ma w wynikach, są jeszcze na portalu. Zdjęte dostają znacznik "nieaktywna".
Schedule::command('listings:check-active')->everyThirtyMinutes()->withoutOverlapping(60)->runInBackground();

// Przypomnienie o oglądaniu (dzwoneczek w panelu) na 2 godziny przed terminem.
Schedule::command('viewings:remind')->everyFiveMinutes()->withoutOverlapping();

// Okazje: przeliczenie i powiadomienia o nowych, co godzinę.
Schedule::command('deals:refresh')->hourlyAt(15)->withoutOverlapping()->runInBackground();

// Sklepy, żłobki, przedszkola i szkoły z OpenStreetMap, pierwszego dnia miesiąca o 3:30.
Schedule::command('pois:import')->monthlyOn(1, '03:30')->withoutOverlapping()->runInBackground();

// Alert, gdy od 3 godzin nie ma udanego przebiegu.
Schedule::command('alerts:check')->hourly();

// Monitor cen: produkty, które czekają na sprawdzenie (co ile godzin: ustawienie produktu albo PRODUCTS_CHECK_EVERY_HOURS).
Schedule::command('products:check')->everyTenMinutes()->withoutOverlapping()->when(fn () => (bool) config('products.enabled'));

// Monitor cen: szukanie produktów w innych sklepach (mapy stron sklepów, AI, opcjonalnie Brave), codziennie o 11:00 dla tych, których dawno nie szukaliśmy.
Schedule::command('products:find-offers --due')->dailyAt('11:00')->withoutOverlapping()
    ->when(fn () => config('products.enabled') && config('products.search.every_days') > 0);

// Monitor cen: mapy stron sklepów z listy (darmowe szukanie produktów), w niedziele o 5:00 te starsze niż tydzień.
Schedule::command('products:sync-shops')->weeklyOn(0, '05:00')->withoutOverlapping()->when(fn () => (bool) config('products.enabled'));
