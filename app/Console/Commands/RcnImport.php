<?php

namespace App\Console\Commands;

use App\Enums\DealType;
use App\Models\Listing;
use App\Services\Rcn\RcnImporter;
use App\Services\Rcn\TransactionComparator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('rcn:import
    {--county=* : TERYT powiatu, domyślnie z ustawień (np. 1863 Rzeszów)}
    {--months= : Ile miesięcy wstecz, domyślnie rcn.import_months}
    {--compare-only : Bez pobierania, tylko porównanie ofert z zapisanymi transakcjami}')]
#[Description('Pobiera ceny transakcyjne mieszkań z Rejestru Cen Nieruchomości i porównuje z nimi oferty sprzedaży')]
class RcnImport extends Command
{
    public function handle(RcnImporter $importer, TransactionComparator $comparator): int
    {
        $failed = false;

        if (! $this->option('compare-only')) {
            $since = now()->subMonths((int) ($this->option('months') ?: config('rcn.import_months')))->startOfMonth();

            foreach ($this->option('county') ?: RcnImporter::counties() as $teryt) {
                $this->line("Powiat {$teryt}: pobieram transakcje od {$since->toDateString()}...");

                try {
                    $saved = $importer->import($teryt, $since, fn (int $saved) => $this->output->write("\r  zapisane: {$saved}"));
                    $this->newLine();
                    $this->info("Powiat {$teryt}: {$saved} transakcji.");
                } catch (Throwable $exception) {
                    report($exception);
                    $this->newLine();
                    $this->error("Powiat {$teryt}: {$exception->getMessage()}");
                    $failed = true;
                }
            }
        }

        $compared = 0;

        Listing::query()
            ->where('deal_type', DealType::Sale)
            ->whereNull('duplicate_of_id')
            ->whereNotNull('lat')
            ->where('last_seen_at', '>=', now()->subDays(60))
            ->chunkById(200, function ($listings) use ($comparator, &$compared) {
                foreach ($listings as $listing) {
                    $listing->forceFill([
                        'transaction_comparison' => $comparator->compare($listing),
                        'transactions_compared_at' => now(),
                    ])->save();
                    $compared++;
                }
            });

        $this->info("Porównane oferty sprzedaży: {$compared}.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
