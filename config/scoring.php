<?php

return [
    'enabled' => (bool) env('AI_ENABLED', true),
    'max_per_minute' => (int) env('AI_MAX_PER_MINUTE', 20),
    'timeout_seconds' => 120,
    // low, high albo max (model deepseek-v4.1-flash). Puste = domyślne modelu.
    'reasoning_effort' => env('AI_REASONING_EFFORT', 'low'),
    // Opis ogłoszenia przycinamy do tylu znaków.
    'description_limit' => 3000,
    // Oferty, które polubiłeś albo odrzuciłeś w panelu (z notatkami), jako przykłady w prompcie oceny.
    'learn_from_decisions' => (bool) env('AI_LEARN_FROM_DECISIONS', true),
    // Ile przykładów każdego rodzaju (polubione, odrzucone), od najnowszych.
    'decision_examples' => (int) env('AI_DECISION_EXAMPLES', 6),
];
