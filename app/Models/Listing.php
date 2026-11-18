<?php

namespace App\Models;

use App\Enums\DealType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    /**
     * Czy ta oferta (albo jej duplikat z drugiego portalu) poszła już na powiadomienia
     * w jakimkolwiek wyszukiwaniu. Jedna oferta pasująca do kilku wyszukiwań idzie raz.
     */
    public function wasNotified(?int $exceptSearchId = null): bool
    {
        $canonicalId = $this->duplicate_of_id ?? $this->id;
        $ids = static::query()
            ->whereKey($canonicalId)
            ->orWhere('duplicate_of_id', $canonicalId)
            ->pluck('id');

        return DB::table('search_listing')
            ->whereIn('listing_id', $ids)
            ->when($exceptSearchId, fn ($query) => $query->where('search_id', '!=', $exceptSearchId))
            ->whereNotNull('notified_at')
            ->exists();
    }
}
