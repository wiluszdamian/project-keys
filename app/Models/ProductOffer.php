<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Oferta produktu w jednym sklepie (link). Historia cen w product_prices.
 */
class ProductOffer extends Model
{
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SEARCH = 'search';

    protected $fillable = [
        'product_id', 'url', 'url_key', 'shop', 'title', 'price', 'regular_price', 'lowest_30d_price', 'currency',
        'available', 'source', 'is_primary', 'is_active', 'extracted_by', 'match_note',
    ];

    protected $attributes = [
        'currency' => 'PLN',
        'source' => self::SOURCE_MANUAL,
        'is_primary' => false,
        'is_active' => true,
        'failures' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'regular_price' => 'float',
            'lowest_30d_price' => 'float',
            'available' => 'boolean',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'failures' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * Czy oferta liczy się do najlepszej ceny: śledzona, z ceną i nie wiadomo, że niedostępna.
     */
    public function isBuyable(): bool
    {
        return $this->is_active && $this->price !== null && $this->available !== false && $this->currency === 'PLN';
    }
}
