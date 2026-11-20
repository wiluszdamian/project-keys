<?php

namespace App\Filament\Pages;

use App\Enums\DealType;
use App\Models\MarketReport;
use App\Services\MarketReportBuilder;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Tygodniowy raport rynku: zapisane raporty z sobót i podgląd na żywo z ostatnich 7 dni.
 */
class MarketReportPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Raport rynku';

    protected static ?string $title = 'Raport rynku';

    protected static ?string $slug = 'market-report';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.market-report';

    /** Id zapisanego raportu albo live-rent / live-sale. */
    #[Url]
    public ?string $report = null;

    public function mount(): void
    {
        if ($this->report === null || $this->resolve() === null) {
            $this->report = (string) (MarketReport::latest('period_end')->value('id') ?? 'live-'.DealType::Rent->value);
        }
    }

    /**
     * @return array<string, string>
     */
    public function getOptionsProperty(): array
    {
        $options = [];

        foreach (DealType::cases() as $deal) {
            $options["live-{$deal->value}"] = "{$deal->getLabel()}: ostatnie 7 dni (na żywo)";
        }

        foreach (MarketReport::latest('period_end')->orderBy('deal_type')->limit(60)->get() as $report) {
            $options[(string) $report->id] = "{$report->deal_type->getLabel()}: {$report->periodLabel()}";
        }

        return $options;
    }

    /**
     * @return array{deal: DealType, label: string, live: bool, data: array<string, mixed>}|null
     */
    public function getCurrentProperty(): ?array
    {
        return $this->resolve();
    }

    /**
     * @return array{deal: DealType, label: string, live: bool, data: array<string, mixed>}|null
     */
    private function resolve(): ?array
    {
        if (str_starts_with((string) $this->report, 'live-')) {
            $deal = DealType::tryFrom(substr($this->report, 5));

            if ($deal === null) {
                return null;
            }

            $to = now();
            $from = $to->copy()->subDays(7);

            return [
                'deal' => $deal,
                'label' => MarketReport::label($from, $to).' (na żywo, nie zapisany)',
                'live' => true,
                'data' => app(MarketReportBuilder::class)->build($deal, $from, $to),
            ];
        }

        $report = ctype_digit((string) $this->report) ? MarketReport::find($this->report) : null;

        return $report ? [
            'deal' => $report->deal_type,
            'label' => $report->periodLabel(),
            'live' => false,
            'data' => $report->data,
        ] : null;
    }

    public static function change(?float $change): ?string
    {
        if ($change === null) {
            return null;
        }

        $value = str_replace('.', ',', (string) round(abs($change), 1)).'%';

        return match (true) {
            $change > 0 => "▲ {$value}",
            $change < 0 => "▼ {$value}",
            default => 'bez zmian',
        };
    }
}
