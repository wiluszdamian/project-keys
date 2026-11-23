<?php

return [
    // Kanały, które można wybrać w wyszukiwaniu.
    'channels' => ['telegram', 'discord'],

    // Powyżej tylu nowych ofert w jednym przebiegu idzie jedno podsumowanie.
    'digest_threshold' => (int) env('NOTIFY_DIGEST_THRESHOLD', 5),
    'digest_size' => 10,
    'price_drops' => (bool) env('NOTIFY_PRICE_DROPS', true),
    // Okazje (DealFinder): powiadomienie, gdy oferta pierwszy raz staje się okazją.
    'deals' => (bool) env('NOTIFY_DEALS', true),
    // Ten sam alert systemowy najwyżej raz na tyle godzin.
    'alert_cooldown_hours' => 6,
    // Tygodniowy raport rynku w każdą sobotę o 20:00: Discord (kanał wynajmu i sprzedaży) i panel.
    'weekly_report' => (bool) env('NOTIFY_WEEKLY_REPORT', true),
    // Alerty systemowe zawsze idą do panelu. Na Discorda tylko po włączeniu.
    'alerts_discord' => (bool) env('NOTIFY_ALERTS_DISCORD', false),

    // Zbiorcze powiadomienie dla kanału (wynajem i sprzedaż osobno), zamiast ustawień każdego wyszukiwania.
    // schedule: per_search (decyduje wyszukiwanie), every_hours (co N godzin) albo daily (raz dziennie o daily_at).
    'groups' => [
        'rent' => [
            'schedule' => env('NOTIFY_RENT_SCHEDULE', 'per_search'),
            'every_hours' => 6,
            'daily_at' => '20:00',
        ],
        'sale' => [
            'schedule' => env('NOTIFY_SALE_SCHEDULE', 'per_search'),
            'every_hours' => 6,
            'daily_at' => '20:00',
        ],
    ],

    'discord' => [
        'webhook_url' => env('DISCORD_WEBHOOK_URL'),
        'webhook_url_rent' => env('DISCORD_WEBHOOK_URL_RENT'),
        'webhook_url_sale' => env('DISCORD_WEBHOOK_URL_SALE'),
        'username' => env('DISCORD_USERNAME', 'Mieszkania'),
        // Przerwa między wiadomościami do tego samego webhooka.
        'delay_ms' => 1500,
    ],
];
