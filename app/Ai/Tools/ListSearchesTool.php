<?php

namespace App\Ai\Tools;

use App\Models\Search;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListSearchesTool implements Tool
{
    public function name(): string
    {
        return 'list_searches';
    }

    public function description(): Stringable|string
    {
        return 'Lista zapisanych wyszukiwań właściciela: id, nazwa, wynajem czy sprzedaż, miejscowość, filtry, preferencje dla AI, liczba ofert.';
    }

    public function handle(Request $request): Stringable|string
    {
        return Search::withCount('listings')->orderBy('id')->get()->map(fn (Search $search) => [
            'id' => $search->id,
            'name' => $search->name,
            'deal_type' => $search->deal_type->value,
            'city' => $search->city,
            'districts' => $search->districts,
            'price' => [$search->price_min, $search->price_max],
            'area' => [$search->area_min, $search->area_max],
            'rooms' => [$search->rooms_min, $search->rooms_max],
            'portals' => $search->portals,
            'preferences' => $search->ai_preferences,
            'min_score_to_notify' => $search->min_score_to_notify,
            'is_active' => $search->is_active,
            'listings_count' => $search->listings_count,
        ])->toJson(JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
