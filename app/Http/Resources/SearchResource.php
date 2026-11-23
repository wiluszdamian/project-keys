<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'deal_type' => $this->deal_type,
            'property_type' => $this->property_type,
            'city' => $this->city,
            'districts' => $this->districts,
            'radius_km' => $this->radius_km,
            'price_min' => $this->price_min,
            'price_max' => $this->price_max,
            'area_min' => $this->area_min,
            'area_max' => $this->area_max,
            'rooms_min' => $this->rooms_min,
            'rooms_max' => $this->rooms_max,
            'market' => $this->market,
            'move_in_ready' => $this->move_in_ready,
            'exclude_visualizations' => $this->exclude_visualizations,
            'portals' => $this->portals,
            'ai_preferences' => $this->ai_preferences,
            'notify_channels' => $this->notify_channels,
            'min_score_to_notify' => $this->min_score_to_notify,
            'is_active' => $this->is_active,
            'notify_enabled' => $this->notify_enabled,
            'notify_mode' => $this->notify_mode,
            'notify_every_hours' => $this->notify_every_hours,
            'notify_daily_at' => $this->notify_daily_at,
            'first_run_completed' => $this->first_run_completed,
            'last_run_at' => $this->last_run_at,
            'listings_count' => $this->whenCounted('listings'),
            'last_scrape_run' => new ScrapeRunResource($this->whenLoaded('latestScrapeRun')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
