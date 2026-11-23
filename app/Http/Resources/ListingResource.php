<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'portal' => $this->portal,
            'external_id' => $this->external_id,
            'url' => $this->url,
            'title' => $this->title,
            'deal_type' => $this->deal_type,
            'price' => $this->price,
            'rent_extra' => $this->rent_extra,
            'area' => $this->area,
            'price_per_m2' => $this->price_per_m2,
            'rooms' => $this->rooms,
            'floor' => $this->floor,
            'city' => $this->city,
            'district' => $this->district,
            'street' => $this->street,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'geo_precision' => $this->geo_precision,
            'is_private_owner' => $this->is_private_owner,
            'status' => $this->status,
            'note' => $this->note,
            'risk_flags' => $this->risk_flags ?? [],
            'transaction_comparison' => $this->transaction_comparison,
            'deal_reasons' => $this->deal_reasons,
            'deal_at' => $this->deal_at,
            'description' => $this->when($request->routeIs('listings.show'), $this->description),
            'image_url' => $this->image_url,
            'duplicate_of_id' => $this->duplicate_of_id,
            'first_seen_at' => $this->first_seen_at,
            'last_seen_at' => $this->last_seen_at,
            'matched_at' => $this->whenHas('matched_at'),
            'analyses' => ListingAnalysisResource::collection($this->whenLoaded('analyses')),
            'commutes' => $this->whenLoaded('commutes', fn () => $this->commutes->map(fn ($commute) => [
                'place_id' => $commute->commute_place_id,
                'place' => $commute->place?->name,
                'mode' => $commute->mode,
                'minutes' => $commute->minutes,
                'km' => $commute->km,
            ])->values()),
            'price_changes' => $this->whenLoaded('priceChanges', fn () => $this->priceChanges->map->only(['old_price', 'new_price', 'changed_at'])),
        ];
    }
}
