<?php

namespace App\Models;

use App\Enums\DealType;
use App\Enums\NotifyMode;
use App\Enums\PropertyType;
use App\Support\NotifySchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Search extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'deal_type', 'property_type', 'city', 'districts', 'radius_km',
        'price_min', 'price_max', 'area_min', 'area_max', 'rooms_min', 'rooms_max',
        'market', 'move_in_ready', 'exclude_visualizations',
        'portals', 'ai_preferences', 'notify_channels', 'min_score_to_notify', 'is_active',
        'notify_enabled', 'notify_mode', 'notify_every_hours', 'notify_daily_at',
    ];

    protected $attributes = [
        'property_type' => 'flat',
        'min_score_to_notify' => 6,
        'is_active' => true,
        'first_run_completed' => false,
        'notify_enabled' => true,
        'notify_mode' => 'instant',
        'move_in_ready' => false,
        'exclude_visualizations' => false,
    ];

    protected function casts(): array
    {
        return [
            'deal_type' => DealType::class,
            'property_type' => PropertyType::class,
            'districts' => 'array',
            'radius_km' => 'integer',
            'portals' => 'array',
            'notify_channels' => 'array',
            'is_active' => 'boolean',
            'move_in_ready' => 'boolean',
            'exclude_visualizations' => 'boolean',
            'notify_enabled' => 'boolean',
            'notify_mode' => NotifyMode::class,
            'notify_last_sent_at' => 'datetime',
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

    /**
     * Czy pora wysłać zebrane oferty (tryb co kilka godzin albo raz dziennie).
     */
    public function scheduledNotifyDue(Carbon $now): bool
    {
        return NotifySchedule::due($this->notify_mode, $this->notify_every_hours, $this->notify_daily_at, $this->notify_last_sent_at, $now);
    }

    /**
     * Opis okresu do nagłówka podsumowania.
     */
    public function scheduledPeriodLabel(): string
    {
        return NotifySchedule::periodLabel($this->notify_mode, $this->notify_every_hours);
    }
}
