<?php

return [
    // Kanały, które można wybrać w wyszukiwaniu.
    'channels' => ['telegram', 'discord'],

    // Powyżej tylu nowych ofert w jednym przebiegu idzie jedno podsumowanie.
    'digest_threshold' => (int) env('NOTIFY_DIGEST_THRESHOLD', 5),
    'digest_size' => 10,
    'price_drops' => (bool) env('NOTIFY_PRICE_DROPS', true),
    // Ten sam alert systemowy najwyżej raz na tyle godzin.
    'alert_cooldown_hours' => 6,

    'discord' => [
        'webhook_url' => env('DISCORD_WEBHOOK_URL'),
        'webhook_url_rent' => env('DISCORD_WEBHOOK_URL_RENT'),
        'webhook_url_sale' => env('DISCORD_WEBHOOK_URL_SALE'),
        'username' => env('DISCORD_USERNAME', 'Mieszkania'),
        // Przerwa między wiadomościami do tego samego webhooka.
        'delay_ms' => 1500,
    ],
];
