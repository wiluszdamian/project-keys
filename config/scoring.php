<?php

return [
    'enabled' => (bool) env('AI_ENABLED', true),
    'max_per_minute' => (int) env('AI_MAX_PER_MINUTE', 20),
    'timeout_seconds' => 120,
    // low, high albo max (model deepseek-v4.1-flash). Puste = domyślne modelu.
    'reasoning_effort' => env('AI_REASONING_EFFORT', 'low'),
    // Opis ogłoszenia przycinamy do tylu znaków.
    'description_limit' => 3000,
];
