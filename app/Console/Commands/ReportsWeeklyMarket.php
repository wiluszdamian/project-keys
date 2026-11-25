<?php

namespace App\Console\Commands;

use App\Enums\DealType;
use App\Models\MarketReport;
use App\Notifications\Notifier;
use App\Notifications\PanelNotifier;
use App\Services\MarketReportBuilder;
use App\Services\MarketReportSummarizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('reports:weekly-market
    {--deal=* : rent albo sale, domyślnie oba}
    {--no-send : Tylko zapisz raport, bez Discorda i dzwoneczka}')]
#[Description('Raport rynku z ostatnich 7 dni: zapis w bazie, Discord (kanał wynajmu albo sprzedaży) i panel')]
class ReportsWeeklyMarket extends Command
{
    public function handle(MarketReportBuilder $builder, MarketReportSummarizer $summarizer, Notifier $notifier, PanelNotifier $panel): int
    {
        $to = now()->startOfMinute();
        $from = $to->copy()->subDays(7);
        $deals = $this->option('deal')
            ? array_map(fn (string $deal) => DealType::from($deal), $this->option('deal'))
            : DealType::cases();

        foreach ($deals as $deal) {
            $data = $builder->build($deal, $from, $to);

            if ($data['totals']['active'] === 0 && $data['searches'] === []) {
                $this->line("{$deal->getLabel()}: brak ofert i wyszukiwań, pomijam.");

                continue;
            }

            // Podsumowanie AI tylko w zapisanym raporcie (podgląd na żywo w panelu go nie ma).
            $data['ai_summary'] = $summarizer->summarize($deal, $data);

            $report = MarketReport::updateOrCreate(
                ['deal_type' => $deal, 'period_end' => $to],
                ['period_start' => $from, 'data' => $data],
            );

            $this->info("{$deal->getLabel()}: {$data['totals']['active']} ofert, {$data['totals']['new']} nowych, mediana ".($data['totals']['median_price'] ?? '-').' zł.');

            if ($this->option('no-send')) {
                continue;
            }

            try {
                $notifier->marketReport($report);
            } catch (Throwable $exception) {
                report($exception);
                $this->warn("Discord: {$exception->getMessage()}");
            }

            $panel->marketReport($report);
            $report->update(['sent_at' => now()]);
            $products = $data['products'];
        }

        // Ceny produktów raz, nie przy każdym raporcie (te same w wynajmie i sprzedaży).
        if (! $this->option('no-send') && ($products ?? []) !== []) {
            try {
                $notifier->productsWeekly($products);
            } catch (Throwable $exception) {
                report($exception);
                $this->warn("Discord (monitor cen): {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
