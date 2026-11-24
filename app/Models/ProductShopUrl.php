<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductShopUrl extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_shop_id', 'url', 'slug'];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(ProductShop::class, 'product_shop_id');
    }
}
