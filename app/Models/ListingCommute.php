<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingCommute extends Model
{
    public const MODES = ['car' => 'autem', 'bike' => 'rowerem'];

    protected $fillable = ['listing_id', 'commute_place_id', 'mode', 'minutes', 'km'];

    protected function casts(): array
    {
        return ['km' => 'float'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(CommutePlace::class, 'commute_place_id');
    }
}
