<?php

namespace App\Ai\Tools;

use App\Models\Listing;
use App\Services\Mortgage\RkmSimulator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Symulacja Rodzinnego Kredytu Mieszkaniowego (jak strona Kredyt) dla oferty albo ceny.
 */
class MortgageTool implements Tool
{
    public function __construct(private RkmSimulator $simulator) {}

    public function name(): string
    {
        return 'mortgage_simulation';
    }

    public function description(): Stringable|string
    {
        return 'Symulacja Rodzinnego Kredytu Mieszkaniowego (gwarancja BGK zamiast wkładu, do 100 000 zł) dla oferty (listing_id) albo podanej ceny. '
            .'Zwraca kwotę kredytu, potrzebny wkład, gwarancję, opłaty, ratę przy stałym (5 lat) i zmiennym oprocentowaniu, ratę po wzroście stóp, odsetki, limit ceny za m² i efekt spłaty rodzinnej.';
    }

    public function handle(Request $request): Stringable|string
    {
        $listing = isset($request['listing_id']) ? Listing::find((int) $request['listing_id']) : null;
        $input = RkmSimulator::defaults($listing);

        foreach (['price', 'area', 'own', 'years', 'second_child_year', 'third_child_year'] as $field) {
            if (isset($request[$field])) {
                $input[$field] = $request[$field];
            }
        }

        if (isset($request['zone'])) {
            $input['zone'] = $request['zone'];
        }

        $variants = [];

        foreach ([RkmSimulator::FIXED, RkmSimulator::VARIABLE] as $type) {
            $r = $this->simulator->simulate(['rate_type' => $type] + $input);
            $variants[$type] = [
                'rate' => $type === RkmSimulator::FIXED ? config('mortgage.fixed_rate') : $r['variable_rate'],
                'installment_first_months' => $r['installment_bridge'] ? (int) round($r['installment_bridge']) : null,
                'installment' => (int) round($r['installment']),
                'installment_after_5y' => $r['installment_after_fixed'] !== null ? (int) round($r['installment_after_fixed']) : null,
                'installment_if_rates_up_2_5pp' => (int) round($r['stress_installment']),
                'total_interest' => (int) round($r['schedule']['interest']),
                'interest_with_family_repayment' => $r['with_children'] ? (int) round($r['with_children']['interest']) : null,
            ];
        }

        $base = $this->simulator->simulate($input);

        return json_encode([
            'listing' => $listing ? ['id' => $listing->id, 'title' => $listing->title] : null,
            'price' => (int) $base['price'],
            'years' => $base['years'],
            'loan' => (int) round($base['loan']),
            'own_contribution' => (int) round($base['own']),
            'bgk_guarantee' => (int) round($base['guarantee']),
            'fees' => (int) round($base['fees_total']),
            'price_limit' => $base['limit'],
            'warnings' => $base['warnings'],
            'variants' => $variants,
            'rates_date' => config('mortgage.rates_date'),
            'page_url' => route('filament.admin.pages.kredyt', $listing ? ['listing' => $listing->id] : []),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'listing_id' => $schema->integer()->description('Oferta sprzedaży (cena, metraż i miejscowość z oferty).'),
            'price' => $schema->integer()->description('Cena mieszkania w zł, gdy bez oferty albo inna niż w ofercie.'),
            'area' => $schema->number()->description('Metraż w m² (do limitu ceny za m²).'),
            'zone' => $schema->string()->enum(['rzeszow', 'neighbour', 'other'])->description('Strefa limitu BGK: Rzeszów, gmina sąsiadująca, reszta województwa.'),
            'own' => $schema->integer()->description('Wkład własny w zł.'),
            'years' => $schema->integer()->description('Okres kredytu w latach (15 do 35).'),
            'second_child_year' => $schema->integer()->description('W którym roku kredytu drugie dziecko (spłata rodzinna 20 000 zł).'),
            'third_child_year' => $schema->integer()->description('W którym roku kredytu trzecie dziecko (spłata rodzinna 60 000 zł).'),
        ];
    }
}
