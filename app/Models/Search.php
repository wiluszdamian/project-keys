<?php

namespace App\Models;

use App\Enums\DealType;
use App\Enums\NotifyMode;
use App\Enums\PropertyType;
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
        'name', 'deal_type', 'property_type', 'city', 'districts',
        'price_min', 'price_max', 'area_min', 'area_max', 'rooms_min', 'rooms_max',
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
        return match ($this->notify_mode) {
            NotifyMode::Instant => true,
            NotifyMode::EveryHours => $this->notify_last_sent_at === null
                || $this->notify_last_sent_at->lte($now->copy()->subHours(max(1, (int) $this->notify_every_hours))),
            NotifyMode::Daily => $this->dailySendTime($now)->lte($now)
                && ($this->notify_last_sent_at === null || $this->notify_last_sent_at->lt($this->dailySendTime($now))),
        };
    }

    /**
     * Opis okresu do nagłówka podsumowania.
     */
    public function scheduledPeriodLabel(): string
    {
        return match ($this->notify_mode) {
            NotifyMode::EveryHours => 'Podsumowanie z ostatnich '.max(1, (int) $this->notify_every_hours).' h',
            NotifyMode::Daily => 'Podsumowanie dnia',
            NotifyMode::Instant => 'Zaległe oferty',
        };
    }

    private function dailySendTime(Carbon $now): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $this->notify_daily_at ?: '20:00') + [1 => 0]);

        return $now->copy()->setTime($hour, $minute);
    }
}
