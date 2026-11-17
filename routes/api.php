<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\ScrapeRunController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::apiResource('searches', SearchController::class);
    Route::post('searches/{search}/pause', [SearchController::class, 'pause']);
    Route::post('searches/{search}/resume', [SearchController::class, 'resume']);
    Route::get('searches/{search}/listings', [SearchController::class, 'listings']);

    Route::get('listings/{listing}', [ListingController::class, 'show'])->name('listings.show');

    Route::get('scrape-runs', [ScrapeRunController::class, 'index']);
});
