<?php

namespace App\Models;

use App\Enums\DealType;
use App\Enums\ListingStatus;
use App\Services\ListingFeatures;
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
            'status' => ListingStatus::class,
            'area' => 'decimal:2',
            'is_private_owner' => 'boolean',
            'raw' => 'array',
            'risk_flags' => 'array',
            'lat' => 'float',
            'lng' => 'float',
            'geocoded_at' => 'datetime',
            'transaction_comparison' => 'array',
            'transactions_compared_at' => 'datetime',
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

    public function commutes(): HasMany
    {
        return $this->hasMany(ListingCommute::class);
    }

    /**
     * Rynek pierwotny albo wtórny, gdy portal to podaje (RcnTransaction::PRIMARY / SECONDARY), inaczej null.
     */
    public function market(): ?string
    {
        return ListingFeatures::market($this->raw ?? [], $this->portal, (string) $this->external_id);
    }

    public function hasCoordinates(): bool
    {
        return $this->lat !== null && $this->lng !== null;
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
