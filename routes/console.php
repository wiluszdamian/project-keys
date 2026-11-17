<?php

use Illuminate\Support\Facades\Schedule;

// Co minutę sprawdza, które wyszukiwania czekają dłużej niż SCRAPE_INTERVAL_MINUTES.
Schedule::command('search:dispatch-due')->everyMinute()->withoutOverlapping();

// Sprzątanie kolejki: stare batche i nieudane joby.
Schedule::command('queue:prune-batches --hours=48')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();

// Alert, gdy od 3 godzin nie ma udanego przebiegu.
Schedule::command('alerts:check')->hourly();
