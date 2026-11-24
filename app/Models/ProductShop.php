<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sklep, w którym monitor cen szuka produktów po mapie strony (sitemap).
 */
class ProductShop extends Model
{
    use HasFactory;

    protected $fillable = ['domain', 'name', 'sitemap_url', 'is_active', 'source'];

    protected $attributes = [
        'is_active' => true,
        'source' => 'manual',
        'urls_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'urls_count' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function urls(): HasMany
    {
        return $this->hasMany(ProductShopUrl::class);
    }

    public function isStale(): bool
    {
        return $this->synced_at === null || $this->synced_at->lt(now()->subDays((int) config('products.shops.sync_every_days')));
    }
}
