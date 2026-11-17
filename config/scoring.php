<?php

return [
    'enabled' => (bool) env('AI_ENABLED', true),
    'max_per_minute' => (int) env('AI_MAX_PER_MINUTE', 20),
    'timeout_seconds' => 60,
    // Opis ogłoszenia przycinamy do tylu znaków.
    'description_limit' => 3000,
];
