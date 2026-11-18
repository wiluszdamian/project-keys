<?php

namespace App\Models;

use App\Jobs\RecomputeCommutesJob;
use App\Services\Geo\Geocoder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Miejsce, do którego liczymy dojazd z każdej oferty (np. praca).
 */
class CommutePlace extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'address', 'lat', 'lng', 'max_minutes', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Nowy albo zmieniony adres bez współrzędnych: szukamy ich w OpenStreetMap.
        static::saving(function (CommutePlace $place) {
            if ($place->isDirty('address') && ! $place->isDirty(['lat', 'lng'])) {
                $place->lat = $place->lng = null;
            }

            if ($place->lat === null && $place->address) {
                $result = app(Geocoder::class)->geocode($place->address.', Polska');
                [$place->lat, $place->lng] = [$result['lat'] ?? null, $result['lng'] ?? null];
            }
        });

        static::saved(function (CommutePlace $place) {
            if ($place->wasChanged(['lat', 'lng', 'is_active']) || $place->wasRecentlyCreated) {
                RecomputeCommutesJob::dispatch($place->id);
            }
        });
    }

    public function commutes(): HasMany
    {
        return $this->hasMany(ListingCommute::class);
    }

    public function scopeUsable(Builder $query): void
    {
        $query->where('is_active', true)->whereNotNull('lat')->whereNotNull('lng');
    }
}
