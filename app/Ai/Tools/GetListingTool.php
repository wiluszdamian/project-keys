<?php

namespace App\Ai\Tools;

use App\Enums\DealType;
use App\Filament\Support\Format;
use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Services\DealFinder;
use App\Services\Geo\Infrastructure;
use App\Services\ListingFacts;
use App\Services\Mortgage\RkmSimulator;
use App\Services\Rcn\TransactionComparator;
use App\Support\Portals;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetListingTool implements Tool
{
    public function name(): string
    {
        return 'get_listings';
    }

    public function description(): Stringable|string
    {
        return 'Pełne dane jednej albo kilku ofert (do 5) po id: opis, szczegóły budynku, oceny AI z plusami i minusami, koszt miesięczny, czynsz i jego źródło, historia cen, porównanie z cenami transakcyjnymi z RCN (sprzedaż), okazja, inne portale z tą ofertą, rata kredytu RKM (sprzedaż), fakty o mieszkaniu i budynku (ogrzewanie, rok budowy, winda, garaż i komórka w cenie albo za dopłatą), okolica (najbliższe sklepy, żłobek, przedszkole, szkoły), status i notatka właściciela.';
    }

    public function handle(Request $request): Stringable|string
    {
        $ids = array_slice(array_map('intval', (array) ($request['ids'] ?? [])), 0, 5);

        $listings = Listing::with(['analyses.search', 'priceChanges', 'searches', 'commutes.place', 'duplicates'])->whereKey($ids)->get()->map(fn (Listing $listing) => [
            'id' => $listing->id,
            'title' => $listing->title,
            'deal_type' => $listing->deal_type->value,
            'price' => $listing->price,
            'admin_fee' => $listing->adminFee()[0],
            'admin_fee_source' => $listing->adminFee()[1],
            'area' => $listing->area !== null ? (float) $listing->area : null,
            'price_per_m2' => $listing->price_per_m2,
            'rooms' => $listing->rooms,
            'floor' => $listing->floor,
            'city' => $listing->city,
            'district' => $listing->district,
            'street' => $listing->street,
            'portal' => Portals::label($listing->portal),
            'also_on' => $listing->duplicates->map(fn (Listing $duplicate) => ['portal' => Portals::label($duplicate->portal), 'price' => $duplicate->price, 'url' => $duplicate->url])->all(),
            'deal' => DealFinder::summary($listing->deal_reasons),
            'mortgage_rkm' => $listing->deal_type === DealType::Sale && $listing->price ? $this->mortgage($listing) : null,
            'private_owner' => $listing->is_private_owner,
            'details' => $listing->raw['details'] ?? null,
            'facts' => ListingFacts::labels($listing),
            'surroundings' => Infrastructure::lines($listing),
            'description' => Str::limit((string) $listing->description, 2500),
            'found' => $listing->first_seen_at?->format('Y-m-d H:i'),
            'last_seen' => $listing->last_seen_at?->format('Y-m-d H:i'),
            'status' => $listing->status?->getLabel(),
            'note' => $listing->note,
            'searches' => $listing->searches->pluck('name'),
            'commutes' => Format::commutes($listing),
            'location_precision' => $listing->geo_precision,
            'risk_flags' => collect($listing->risk_flags ?? [])->pluck('message')->all(),
            'transaction_prices' => $listing->transaction_comparison ? [
                'summary' => TransactionComparator::describe($listing->transaction_comparison),
                'details' => $listing->transaction_comparison,
            ] : null,
            'analyses' => $listing->analyses->map(fn (ListingAnalysis $analysis) => [
                'search' => $analysis->search?->name,
                'score' => $analysis->score,
                'sub_scores' => $analysis->sub_scores,
                'summary' => $analysis->summary,
                'monthly_cost' => $analysis->monthly_cost,
                'cost_breakdown' => $analysis->cost_breakdown,
                'pros' => $analysis->pros,
                'red_flags' => $analysis->red_flags,
            ]),
            'price_history' => $listing->priceChanges->map(fn ($change) => [
                'date' => $change->changed_at->format('Y-m-d'),
                'old' => $change->old_price,
                'new' => $change->new_price,
            ]),
            'panel_url' => route('filament.admin.resources.listings.view', $listing),
            'offer_url' => $listing->url,
        ]);

        return $listings->isEmpty() ? 'Nie ma ofert o takich id.' : $listings->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Krótko: rata RKM (stałe 5 lat, 30 lat) przy domyślnych stawkach.
     */
    private function mortgage(Listing $listing): array
    {
        $result = app(RkmSimulator::class)->simulate(RkmSimulator::defaults($listing));

        return [
            'loan' => (int) round($result['loan']),
            'own_contribution' => (int) round($result['own']),
            'installment_fixed_5y' => (int) round($result['installment']),
            'installment_after_5y' => (int) round($result['installment_after_fixed'] ?? $result['installment']),
            'price_limit_ok' => $result['limit']['ok'] ?? null,
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ids' => $schema->array()->items($schema->integer())->required()->description('Id ofert z find_listings.'),
        ];
    }
}
