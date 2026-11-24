<?php

namespace App\Services\Products;

use App\Ai\Agents\ProductAdviceAgent;
use App\Filament\Support\Format;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductPrice;
use App\Services\ListingScorer;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ocena AI: czy obecna cena jest dobra i czy kupić teraz, czy poczekać.
 */
class ProductAdvisor
{
    public const VERDICTS = ['buy' => 'Kupuj', 'good' => 'Cena w porządku', 'wait' => 'Poczekaj'];

    public function __construct(private ListingScorer $scorer) {}

    /**
     * @return array{verdict: string, score: int, summary: string, reasons: list<string>, tips: list<string>}
     */
    public function advise(Product $product): array
    {
        $product->load('offers.prices');
        $this->scorer->countCall();

        try {
            $data = ProductAdviceAgent::make()->prompt(self::prompt($product))->toArray();
        } catch (Throwable $exception) {
            $this->scorer->countError();

            throw $exception;
        }

        $strings = fn ($list, int $max) => collect(is_array($list) ? $list : [])->filter(fn ($item) => is_string($item) && trim($item) !== '')->map(fn (string $item) => Str::limit(trim($item), 300))->take($max)->values()->all();
        $advice = [
            'verdict' => array_key_exists($data['verdict'] ?? '', self::VERDICTS) ? $data['verdict'] : 'good',
            'score' => max(1, min(10, (int) ($data['score'] ?? 5))),
            'summary' => Str::limit(trim((string) ($data['summary'] ?? '')), 600),
            'reasons' => $strings($data['reasons'] ?? [], 4),
            'tips' => $strings($data['tips'] ?? [], 3),
            'price' => $product->best_price,
        ];

        $product->forceFill(['ai_advice' => $advice, 'ai_advice_at' => now()])->save();

        return $advice;
    }

    public static function prompt(Product $product): string
    {
        $tracked = $product->offers->min(fn (ProductOffer $offer) => $offer->prices->min('checked_at'));
        $lines = [
            'Dzisiaj jest '.now()->format('Y-m-d').'.',
            '',
            '## Produkt',
            "- nazwa: {$product->name}",
            $product->brand ? "- marka: {$product->brand}" : null,
            '- najlepsza obecna cena: '.(Format::pln($product->best_price) ?? 'brak (niedostępny)'),
            '- pierwsza cena, gdy zaczęliśmy śledzić: '.(Format::pln($product->initial_price) ?? 'nieznana'),
            '- najniższa widziana: '.(Format::pln($product->lowest_price) ?? 'nieznana').($product->lowest_price_at ? ' ('.$product->lowest_price_at->format('Y-m-d').')' : ''),
            '- najwyższa widziana: '.(Format::pln($product->highest_price) ?? 'nieznana'),
            '- śledzimy od: '.($tracked ? $tracked->format('Y-m-d').' ('.(int) $tracked->diffInDays(now()).' dni)' : 'dziś'),
            '- cena docelowa osoby: '.(Format::pln($product->target_price) ?? 'nie ustawiona'),
            $product->note ? '- notatka osoby: '.Str::squish($product->note) : null,
            '',
            '## Oferty w sklepach',
        ];

        foreach ($product->offers->where('is_active', true) as $offer) {
            $history = $offer->prices->sortBy('checked_at')->take(-15)
                ->map(fn (ProductPrice $price) => $price->checked_at->format('Y-m-d').': '.($price->available === false ? 'niedostępny' : Format::pln($price->price)))
                ->implode('; ');

            $lines[] = implode("\n", array_filter([
                "### {$offer->shop}".($offer->is_primary ? ' (sklep, od którego zaczęliśmy)' : ''),
                '- cena: '.(Format::pln($offer->price) ?? 'nieznana').match ($offer->available) {
                    true => ', dostępny',
                    false => ', niedostępny',
                    null => '',
                },
                $offer->regular_price ? '- cena przed promocją: '.Format::pln($offer->regular_price) : null,
                $offer->lowest_30d_price ? '- najniższa cena z 30 dni (Omnibus): '.Format::pln($offer->lowest_30d_price) : null,
                $history ? "- historia zmian: {$history}" : null,
            ]));
        }

        return implode("\n", array_filter($lines, fn ($line) => $line !== null));
    }
}
