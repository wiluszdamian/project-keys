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

// Alert, gdy od 3 godzin nie ma udanego przebiegu.
Schedule::command('alerts:check')->hourly();
