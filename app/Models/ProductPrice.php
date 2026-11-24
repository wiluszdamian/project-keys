<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wpis w historii ceny oferty: tylko pierwsze sprawdzenie i zmiany ceny albo dostępności.
 */
class ProductPrice extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_offer_id', 'price', 'available', 'checked_at'];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'available' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(ProductOffer::class, 'product_offer_id');
    }
}
