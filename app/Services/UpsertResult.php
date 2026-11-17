<?php

namespace App\Services;

use App\Models\Listing;

final readonly class UpsertResult
{
    public function __construct(
        public Listing $listing,
        public bool $isNew,
        public bool $priceChanged = false,
        public ?int $oldPrice = null,
    ) {}
}
