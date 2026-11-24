<?php

use App\Http\Controllers\Api\CommutePlaceController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\MarketReportController;
use App\Http\Controllers\Api\NotificationSettingsController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ScrapeRunController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::apiResource('searches', SearchController::class);
    Route::post('searches/{search}/run', [SearchController::class, 'run']);
    Route::post('searches/{search}/pause', [SearchController::class, 'pause']);
    Route::post('searches/{search}/resume', [SearchController::class, 'resume']);
    Route::get('searches/{search}/listings', [SearchController::class, 'listings']);

    Route::get('listings/{listing}', [ListingController::class, 'show'])->name('listings.show');

    Route::get('scrape-runs', [ScrapeRunController::class, 'index']);
    Route::apiResource('commute-places', CommutePlaceController::class);
    Route::get('notification-settings', [NotificationSettingsController::class, 'show']);
    Route::put('notification-settings', [NotificationSettingsController::class, 'update']);
    Route::get('market-reports', [MarketReportController::class, 'index']);

    // Monitor cen produktów.
    Route::apiResource('products', ProductController::class);
    Route::post('products/{product}/check', [ProductController::class, 'check']);
    Route::get('products/{product}/prices', [ProductController::class, 'prices']);
    Route::post('products/{product}/offers', [ProductController::class, 'addOffer']);
    Route::delete('products/{product}/offers/{offer}', [ProductController::class, 'destroyOffer']);
    Route::post('products/{product}/find-offers', [ProductController::class, 'findOffers']);
    Route::post('products/{product}/advice', [ProductController::class, 'advice']);
});
