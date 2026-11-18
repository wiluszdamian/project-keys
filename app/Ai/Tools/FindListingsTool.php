<?php

namespace App\Ai\Tools;

use App\Enums\ListingStatus;
use App\Filament\Support\Format;
use App\Models\Listing;
use App\Support\Portals;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Wyszukiwanie ofert w bazie po kryteriach. Zwraca krótkie wiersze, szczegóły daje GetListingTool.
 */
class FindListingsTool implements Tool
{
    public function name(): string
    {
        return 'find_listings';
    }

    public function description(): Stringable|string
    {
        return 'Szuka ofert w bazie (bez duplikatów z innych portali). Wszystkie parametry opcjonalne. '
            .'Zwraca do 20 ofert z ceną, czynszem, kosztem miesięcznym z oceny AI, metrażem, pokojami, lokalizacją, oceną AI, statusem i linkami.';
    }

    public function handle(Request $request): Stringable|string
    {
        $data = $request->all();
        $limit = min(20, max(1, (int) ($data['limit'] ?? 10)));
        $text = isset($data['text']) ? trim((string) $data['text']) : null;

        $query = Listing::query()
            ->whereNull('duplicate_of_id')
            ->withMax('analyses', 'score')
            ->withMax('analyses', 'monthly_cost')
            ->when($data['search_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('searches', fn (Builder $q) => $q->whereKey($id)))
            ->when($data['deal_type'] ?? null, fn (Builder $q, $deal) => $q->where('deal_type', $deal))
            ->when($data['city'] ?? null, fn (Builder $q, $city) => $q->where('city', 'ilike', $city))
            ->when($data['district'] ?? null, fn (Builder $q, $district) => $q->where('district', 'ilike', "%{$district}%"))
            ->when($text, fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('title', 'ilike', "%{$text}%")->orWhere('description', 'ilike', "%{$text}%")->orWhere('street', 'ilike', "%{$text}%")))
            ->when($data['max_price'] ?? null, fn (Builder $q, $v) => $q->where('price', '<=', $v))
            ->when($data['min_price'] ?? null, fn (Builder $q, $v) => $q->where('price', '>=', $v))
            ->when($data['min_area'] ?? null, fn (Builder $q, $v) => $q->where('area', '>=', $v))
            ->when($data['max_area'] ?? null, fn (Builder $q, $v) => $q->where('area', '<=', $v))
            ->when($data['min_rooms'] ?? null, fn (Builder $q, $v) => $q->where('rooms', '>=', $v))
            ->when($data['min_score'] ?? null, fn (Builder $q, $v) => $q->whereHas('analyses', fn (Builder $q) => $q->where('score', '>=', $v)))
            ->when(($data['max_commute_minutes'] ?? null) !== null, fn (Builder $q) => $q->whereHas('commutes', fn (Builder $q) => $q
                ->where('mode', $data['commute_mode'] ?? 'car')
                ->where('minutes', '<=', (int) $data['max_commute_minutes'])
                ->when($data['commute_place_id'] ?? null, fn (Builder $q, $id) => $q->where('commute_place_id', $id))))
            ->when($data['days'] ?? null, fn (Builder $q, $v) => $q->where('first_seen_at', '>=', now()->subDays((int) $v)))
            ->when($data['status'] ?? null, fn (Builder $q, $status) => $status === 'none' ? $q->whereNull('status') : $q->where('status', $status))
            ->when(($data['status'] ?? null) === null, fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('status')->orWhere('status', '!=', ListingStatus::Rejected->value)));

        $query = match ($data['sort'] ?? 'score') {
            'newest' => $query->orderByDesc('first_seen_at'),
            'price' => $query->orderBy('price'),
            'price_per_m2' => $query->orderByRaw('price_per_m2 asc nulls last'),
            default => $query->orderByRaw('analyses_max_score desc nulls last')->orderByDesc('first_seen_at'),
        };

        $total = (clone $query)->count();
        $rows = $query->with('commutes.place')->limit($limit)->get()->map(fn (Listing $listing) => [
            'id' => $listing->id,
            'title' => Str::limit($listing->title, 90),
            'deal_type' => $listing->deal_type->value,
            'price' => $listing->price,
            'rent_extra' => $listing->rent_extra,
            'monthly_cost_ai' => $listing->analyses_max_monthly_cost,
            'area' => $listing->area !== null ? (float) $listing->area : null,
            'price_per_m2' => $listing->price_per_m2,
            'rooms' => $listing->rooms,
            'floor' => $listing->floor,
            'location' => collect([$listing->city, $listing->district, $listing->street])->filter()->unique()->implode(', '),
            'portal' => Portals::label($listing->portal),
            'private_owner' => $listing->is_private_owner,
            'score' => $listing->analyses_max_score,
            'commutes' => Format::commutes($listing),
            'risk_flags' => collect($listing->risk_flags ?? [])->pluck('message')->all(),
            'status' => $listing->status?->getLabel(),
            'found' => $listing->first_seen_at?->format('Y-m-d H:i'),
            'panel_url' => route('filament.admin.resources.listings.view', $listing),
            'offer_url' => $listing->url,
        ]);

        return json_encode(['total_matching' => $total, 'shown' => $rows->count(), 'listings' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search_id' => $schema->integer()->description('Tylko oferty z tego wyszukiwania (id z list_searches).'),
            'deal_type' => $schema->string()->enum(['rent', 'sale']),
            'city' => $schema->string()->description('Miejscowość, np. Rzeszów.'),
            'district' => $schema->string()->description('Fragment nazwy dzielnicy lub osiedla.'),
            'text' => $schema->string()->description('Fragment tekstu w tytule, opisie lub ulicy, np. "Projektant", "balkon", "garaż".'),
            'min_price' => $schema->integer(),
            'max_price' => $schema->integer(),
            'min_area' => $schema->number(),
            'max_area' => $schema->number(),
            'min_rooms' => $schema->integer(),
            'min_score' => $schema->integer()->description('Ocena AI od (1-10).'),
            'days' => $schema->integer()->description('Tylko oferty znalezione w ostatnich N dniach.'),
            'status' => $schema->string()->enum(['favorite', 'contacted', 'viewing', 'rejected', 'none'])->description('Status nadany przez właściciela. Bez tego parametru odrzucone są pomijane.'),
            'max_commute_minutes' => $schema->integer()->description('Najdłuższy dojazd w minutach do miejsc dojazdu (np. praca).'),
            'commute_mode' => $schema->string()->enum(['car', 'bike'])->description('Dojazd autem (domyślnie) albo rowerem.'),
            'commute_place_id' => $schema->integer()->description('Konkretne miejsce dojazdu (id z list_searches -> commute_places). Puste = dowolne.'),
            'sort' => $schema->string()->enum(['score', 'newest', 'price', 'price_per_m2']),
            'limit' => $schema->integer()->description('Ile ofert, najwyżej 20.'),
        ];
    }
}
