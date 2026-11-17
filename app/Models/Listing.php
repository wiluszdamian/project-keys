<?php

namespace App\Models;

use App\Enums\DealType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Listing extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['raw'];

    protected function casts(): array
    {
        return [
            'deal_type' => DealType::class,
            'area' => 'decimal:2',
            'is_private_owner' => 'boolean',
            'raw' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function searches(): BelongsToMany
    {
        return $this->belongsToMany(Search::class, 'search_listing')
            ->using(SearchListing::class)
            ->withPivot(['matched_at', 'notified_at', 'notify_skipped_reason']);
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(ListingAnalysis::class);
    }

    public function priceChanges(): HasMany
    {
        return $this->hasMany(ListingPriceChange::class)->orderBy('changed_at');
    }
}
