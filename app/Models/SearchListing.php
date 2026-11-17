<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class SearchListing extends Pivot
{
    protected $table = 'search_listing';

    public $incrementing = true;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'matched_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }
}
