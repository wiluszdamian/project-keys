<?php

namespace App\Ai\Tools;

use App\Models\Listing;
use App\Models\ListingAnalysis;
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
        return 'Pełne dane jednej albo kilku ofert (do 5) po id: opis, szczegóły budynku, oceny AI z plusami i minusami, koszt miesięczny, historia cen, status i notatka właściciela.';
    }

    public function handle(Request $request): Stringable|string
    {
        $ids = array_slice(array_map('intval', (array) ($request['ids'] ?? [])), 0, 5);

        $listings = Listing::with(['analyses.search', 'priceChanges', 'searches'])->whereKey($ids)->get()->map(fn (Listing $listing) => [
            'id' => $listing->id,
            'title' => $listing->title,
            'deal_type' => $listing->deal_type->value,
            'price' => $listing->price,
            'rent_extra' => $listing->rent_extra,
            'area' => $listing->area !== null ? (float) $listing->area : null,
            'price_per_m2' => $listing->price_per_m2,
            'rooms' => $listing->rooms,
            'floor' => $listing->floor,
            'city' => $listing->city,
            'district' => $listing->district,
            'street' => $listing->street,
            'portal' => Portals::label($listing->portal),
            'private_owner' => $listing->is_private_owner,
            'details' => $listing->raw['details'] ?? null,
            'description' => Str::limit((string) $listing->description, 2500),
            'found' => $listing->first_seen_at?->format('Y-m-d H:i'),
            'last_seen' => $listing->last_seen_at?->format('Y-m-d H:i'),
            'status' => $listing->status?->getLabel(),
            'note' => $listing->note,
            'searches' => $listing->searches->pluck('name'),
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

    public function schema(JsonSchema $schema): array
    {
        return [
            'ids' => $schema->array()->items($schema->integer())->required()->description('Id ofert z find_listings.'),
        ];
    }
}
