<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sprzedaż mieszkania z Rejestru Cen Nieruchomości.
 */
class RcnTransaction extends Model
{
    public const PRIMARY = 'primary';

    public const SECONDARY = 'secondary';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'area' => 'float',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }
}
