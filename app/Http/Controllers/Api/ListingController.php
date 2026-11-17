<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Listing;

class ListingController extends Controller
{
    public function show(Listing $listing): ListingResource
    {
        return new ListingResource($listing->load(['analyses', 'priceChanges']));
    }
}
