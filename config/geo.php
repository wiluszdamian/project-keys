<?php

return [
    // Współrzędne adresów: OpenStreetMap Nominatim (zgoda właściciela 2026-11-18). Najwyżej 1 zapytanie na sekundę.
    'nominatim' => [
        'url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => 'project-keys/1.0 (prywatna wyszukiwarka mieszkan)',
    ],

    // Trasy: własny OSRM w sieci Compose (scripts/osrm-prepare.sh).
    'osrm' => [
        'car' => env('OSRM_CAR_URL', 'http://osrm-car:5000'),
        'bike' => env('OSRM_BIKE_URL', 'http://osrm-bike:5000'),
    ],

    // Ile ofert naraz w jednym zapytaniu do OSRM.
    'batch' => 100,
];
