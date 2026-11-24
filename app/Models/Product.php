<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Produkt w monitorze cen: rzecz, którą chcemy kupić, z ofertami w kilku sklepach.
 */
class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'image_url', 'brand', 'gtin', 'mpn', 'search_query', 'target_price', 'notify_drops', 'min_drop_percent',
        'notify_rises', 'notify_stock', 'check_every_hours', 'is_active', 'note',
    ];

    protected $attributes = [
        'notify_drops' => true,
        'min_drop_percent' => 0,
        'notify_rises' => false,
        'notify_stock' => true,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'target_price' => 'float',
            'best_price' => 'float',
            'initial_price' => 'float',
            'lowest_price' => 'float',
            'highest_price' => 'float',
            'notify_drops' => 'boolean',
            'notify_rises' => 'boolean',
            'notify_stock' => 'boolean',
            'is_active' => 'boolean',
            'min_drop_percent' => 'integer',
            'check_every_hours' => 'integer',
            'lowest_price_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'offers_searched_at' => 'datetime',
            'ai_advice' => 'array',
            'ai_advice_at' => 'datetime',
        ];
    }

    public function offers(): HasMany
    {
        return $this->hasMany(ProductOffer::class);
    }

    public function prices(): HasManyThrough
    {
        return $this->hasManyThrough(ProductPrice::class, ProductOffer::class);
    }

    public function bestOffer(): BelongsTo
    {
        return $this->belongsTo(ProductOffer::class, 'best_offer_id');
    }

    public function primaryOffer(): ?ProductOffer
    {
        return $this->offers->firstWhere('is_primary', true) ?? $this->offers->first();
    }

    public function everyHours(): int
    {
        return $this->check_every_hours ?: (int) config('products.check_every_hours');
    }

    /**
     * Aktywne produkty, które czekają na sprawdzenie.
     */
    public function scopeDue(Builder $query): Builder
    {
        $default = (int) config('products.check_every_hours');

        return $query->where('is_active', true)->where(fn (Builder $query) => $query
            ->whereNull('last_checked_at')
            ->orWhereRaw("last_checked_at <= now() - make_interval(hours => coalesce(check_every_hours, {$default}))"));
    }

    /**
     * Ile procent od pierwszej ceny (ujemne = taniej).
     */
    public function changePercent(): ?float
    {
        if (! $this->initial_price || $this->best_price === null) {
            return null;
        }

        return round(($this->best_price - $this->initial_price) / $this->initial_price * 100, 1);
    }

    public function targetReached(): bool
    {
        return $this->target_price !== null && $this->best_price !== null && $this->best_price <= $this->target_price;
    }
}
