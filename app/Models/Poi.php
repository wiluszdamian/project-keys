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

    public const LABELS = [
        self::SHOP => 'Sklepy',
        self::NURSERY => 'Żłobek',
        self::KINDERGARTEN => 'Przedszkole',
        self::PRIMARY => 'Szkoła podstawowa',
        self::SECONDARY => 'Szkoła średnia',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float'];
    }
}
