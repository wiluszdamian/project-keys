<?php

return [
    // Portale, które można wybrać w wyszukiwaniu.
    'portals' => ['otodom', 'olx'],

    'interval_minutes' => (int) env('SCRAPE_INTERVAL_MINUTES', 20),
    'delay_ms' => (int) env('SCRAPER_DELAY_MS', 4000),
    'max_pages' => (int) env('SCRAPER_MAX_PAGES', 3),
    'user_agent' => env('SCRAPER_USER_AGENT', 'Mozilla/5.0'),
];
