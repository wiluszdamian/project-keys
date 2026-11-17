<?php

return [
    // Kanały, które można wybrać w wyszukiwaniu.
    'channels' => ['telegram', 'discord'],

    'digest_threshold' => (int) env('NOTIFY_DIGEST_THRESHOLD', 5),
    'price_drops' => (bool) env('NOTIFY_PRICE_DROPS', true),
];
