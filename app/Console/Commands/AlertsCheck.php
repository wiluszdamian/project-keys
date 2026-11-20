<?php

namespace App\Console\Commands;

use App\Enums\ScrapeRunStatus;
use App\Models\ScrapeRun;
use App\Models\Search;
use App\Notifications\Notifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('alerts:check')]
#[Description('Sprawdza, czy system działa: udany przebieg w ostatnich 3 godzinach, błędy AI')]
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
                'Ostatni udany przebieg: '.($lastOk ?? 'nigdy').'. Sprawdź Logi i Przebiegi w panelu.',
            );
            $this->warn('Brak udanego przebiegu od 3 godzin.');
        }

        $this->checkAiErrors($notifier);

        return self::SUCCESS;
    }

    /**
     * Błędy AI w ponad połowie wywołań z ostatniej godziny (co najmniej 4 wywołania).
     */
    private function checkAiErrors(Notifier $notifier): void
    {
        $hours = [now()->format('YmdH'), now()->subHour()->format('YmdH')];
        $calls = array_sum(array_map(fn ($hour) => (int) Cache::get("ai:calls:{$hour}", 0), $hours));
        $errors = array_sum(array_map(fn ($hour) => (int) Cache::get("ai:errors:{$hour}", 0), $hours));

        if ($calls >= 4 && $errors / $calls > 0.5) {
            $notifier->alert(
                'ai-errors',
                'Ocena AI często się nie udaje',
                "Błędy w {$errors} z {$calls} wywołań w ostatniej godzinie. Oferty idą bez oceny. Sprawdź Logi w panelu i klucz API w Ustawieniach → AI.",
            );
            $this->warn('Dużo błędów AI.');
        }
    }
}
