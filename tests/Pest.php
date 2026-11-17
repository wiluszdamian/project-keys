<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/**
 * Loguje właściciela tokenem Sanctum.
 */
function actingAsOwner(): User
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    return $user;
}

function validSearchPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Kraków wynajem',
        'deal_type' => 'rent',
        'city' => 'Kraków',
        'districts' => ['Krowodrza', 'Bronowice'],
        'price_min' => 2000,
        'price_max' => 3500,
        'area_min' => 35,
        'area_max' => 60,
        'rooms_min' => 2,
        'rooms_max' => 3,
        'portals' => ['otodom', 'olx'],
        'ai_preferences' => 'balkon, bez agencji',
        'notify_channels' => ['telegram', 'discord'],
        'min_score_to_notify' => 7,
    ], $overrides);
}
