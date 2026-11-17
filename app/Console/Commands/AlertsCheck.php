<?php

namespace App\Console\Commands;

use App\Enums\ScrapeRunStatus;
use App\Models\ScrapeRun;
use App\Models\Search;
use App\Notifications\Notifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:check')]
#[Description('Sprawdza, czy system działa (udany przebieg w ostatnich 3 godzinach)')]
class AlertsCheck extends Command
{
    public function handle(Notifier $notifier): int
    {
        if (! Search::active()->exists()) {
            return self::SUCCESS;
        }

        $lastOk = ScrapeRun::where('status', ScrapeRunStatus::Ok)->max('finished_at');

        if ($lastOk === null || now()->diffInHours($lastOk, absolute: true) >= 3) {
            $notifier->alert(
                'no-successful-run',
                'Brak udanego przebiegu od 3 godzin',
                'Ostatni udany przebieg: '.($lastOk ?? 'nigdy').'. Sprawdź `docker compose logs worker scheduler` i `GET /api/scrape-runs`.',
            );
            $this->warn('Brak udanego przebiegu od 3 godzin.');
        }

        return self::SUCCESS;
    }
}
