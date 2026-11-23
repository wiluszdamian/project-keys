<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Punkt z OpenStreetMap: sklep, żłobek, przedszkole, szkoła podstawowa albo średnia.
 */
class Poi extends Model
{
    public const SHOP = 'shop';

    public const NURSERY = 'nursery';

    public const KINDERGARTEN = 'kindergarten';

    public const PRIMARY = 'primary';

    public const SECONDARY = 'secondary';

    public const PEDIATRIC = 'pediatric';

    public const CLINIC = 'clinic';

    // Stałe miejsca z config infrastructure.places (np. szpital), liczone autem.
    public const HOSPITAL = 'hospital';

    public const LABELS = [
        self::SHOP => 'Sklepy',
        self::NURSERY => 'Żłobek',
        self::KINDERGARTEN => 'Przedszkole',
        self::PRIMARY => 'Szkoła podstawowa',
        self::SECONDARY => 'Szkoła średnia',
        self::PEDIATRIC => 'Przychodnia z pediatrą',
        self::CLINIC => 'Przychodnia (POZ)',
        self::HOSPITAL => 'Szpital',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float'];
    }
}
