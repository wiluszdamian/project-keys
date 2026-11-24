<?php

namespace App\Filament\Resources\Products\Widgets;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductPrice;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Historia ceny produktu: jedna linia na sklep (schodki, bo zapisujemy tylko zmiany).
 */
class ProductPriceChart extends ChartWidget
{
    public ?Model $record = null;

    protected ?string $heading = 'Historia ceny';

    protected static bool $isLazy = false;

    protected ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = 'full';

    private const COLORS = ['#f59e0b', '#3b82f6', '#10b981', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#64748b'];

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        if (! $this->record instanceof Product) {
            return ['datasets' => [], 'labels' => []];
        }

        $offers = $this->record->offers()->with(['prices' => fn ($query) => $query->orderBy('checked_at')])->get();
        $labels = $offers->flatMap(fn (ProductOffer $offer) => $offer->prices->map(fn (ProductPrice $price) => $price->checked_at->format('Y-m-d')))
            ->push(now()->format('Y-m-d'))
            ->unique()
            ->sort()
            ->values();

        $datasets = $offers->values()->map(function (ProductOffer $offer, int $index) use ($labels) {
            $points = $labels->map(function (string $day) use ($offer) {
                $last = $offer->prices->filter(fn (ProductPrice $price) => $price->checked_at->format('Y-m-d') <= $day)->last();

                return $last === null || $last->available === false ? null : $last->price;
            });

            return [
                'label' => $offer->shop.($offer->is_active ? '' : ' (nie śledzę)'),
                'data' => $points->all(),
                'borderColor' => self::COLORS[$index % count(self::COLORS)],
                'backgroundColor' => self::COLORS[$index % count(self::COLORS)],
                'stepped' => true,
                'spanGaps' => false,
                'pointRadius' => 2,
            ];
        });

        if ($this->record->target_price) {
            $datasets->push([
                'label' => 'Cena docelowa',
                'data' => $labels->map(fn () => $this->record->target_price)->all(),
                'borderColor' => '#22c55e',
                'borderDash' => [6, 4],
                'pointRadius' => 0,
                'fill' => false,
            ]);
        }

        return [
            'datasets' => $datasets->all(),
            'labels' => $labels->map(fn (string $day) => Carbon::parse($day)->format('d.m'))->all(),
        ];
    }
}
