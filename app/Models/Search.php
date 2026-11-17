<?php

namespace App\Models;

use App\Enums\DealType;
use App\Enums\PropertyType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Search extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'deal_type', 'property_type', 'city', 'districts',
        'price_min', 'price_max', 'area_min', 'area_max', 'rooms_min', 'rooms_max',
        'portals', 'ai_preferences', 'notify_channels', 'min_score_to_notify', 'is_active',
    ];

    protected $attributes = [
        'property_type' => 'flat',
        'min_score_to_notify' => 6,
        'is_active' => true,
        'first_run_completed' => false,
    ];

    protected function casts(): array
    {
        return [
            'deal_type' => DealType::class,
            'property_type' => PropertyType::class,
            'districts' => 'array',
            'portals' => 'array',
            'notify_channels' => 'array',
            'is_active' => 'boolean',
            'first_run_completed' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'search_listing')
            ->using(SearchListing::class)
            ->withPivot(['matched_at', 'notified_at', 'notify_skipped_reason']);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(ListingAnalysis::class);
    }

    public function scrapeRuns(): HasMany
    {
        return $this->hasMany(ScrapeRun::class);
    }

    public function latestScrapeRun(): HasOne
    {
        return $this->hasOne(ScrapeRun::class)->latestOfMany('started_at');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
