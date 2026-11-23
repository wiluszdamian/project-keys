<?php

namespace App\Models;

use App\Enums\DealType;
use App\Enums\ListingStatus;
use App\Services\ListingFeatures;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
            'deal_reasons' => 'array',
            'deal_at' => 'datetime',
            'deal_notified_at' => 'datetime',
            'infrastructure' => 'array',
            'infrastructure_at' => 'datetime',
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

    /**
     * Oryginał, gdy ta oferta jest duplikatem z innego portalu (ocenę AI ma tylko oryginał).
     */
    public function originalListing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'duplicate_of_id');
    }

    /**
     * Ocena AI: własna albo oryginału, gdy to duplikat. Wymaga withMax('analyses', 'score') (także na original).
     */
    public function bestScore(): ?int
    {
        $score = $this->getAttribute('analyses_max_score') ?? $this->originalListing?->getAttribute('analyses_max_score');

        return $score !== null ? (int) $score : null;
    }

    /**
     * Ta sama oferta z innych portali (duplikaty wskazują na tę ofertę).
     */
    public function duplicates(): HasMany
    {
        return $this->hasMany(Listing::class, 'duplicate_of_id');
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

    /**
     * Zmienia status. Powód (np. dlaczego odrzucona) dopisuje do notatki, z której korzysta ocena AI.
     */
    public function applyStatus(?ListingStatus $status, ?string $reason = null): void
    {
        $note = trim((string) $this->note);
        $reason = trim((string) $reason);

        if ($reason !== '') {
            $note = trim($note."\n".($status?->getLabel() ?? 'Status').': '.$reason);
        }

        $this->update(['status' => $status, 'note' => $note !== '' ? $note : null]);
    }

    /**
     * Czynsz administracyjny najmu i skąd go mamy: z portalu, z innego portalu tej samej oferty
     * albo z opisu (ocena AI). [null, null], gdy nigdzie go nie ma.
     *
     * @return array{0: ?int, 1: 'portal'|'duplicate'|'description'|null}
     */
    public function adminFee(): array
    {
        if ($this->rent_extra !== null) {
            return [$this->rent_extra, 'portal'];
        }

        $fromDuplicate = $this->duplicates->whereNotNull('rent_extra')->first()?->rent_extra;

        if ($fromDuplicate !== null) {
            return [$fromDuplicate, 'duplicate'];
        }

        $fromDescription = $this->analyses->map(fn (ListingAnalysis $analysis) => $analysis->cost_breakdown['admin_fee'] ?? null)->filter()->first();

        if ($fromDescription !== null) {
            return [(int) $fromDescription, 'description'];
        }

        // Duplikat: czynsz z oryginału (z portalu oryginału albo z jego oceny AI).
        if ($this->duplicate_of_id !== null && $this->originalListing !== null) {
            [$fee, $source] = $this->originalListing->adminFee();

            return $fee !== null ? [$fee, $source === 'description' ? 'description' : 'duplicate'] : [null, null];
        }

        return [null, null];
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
