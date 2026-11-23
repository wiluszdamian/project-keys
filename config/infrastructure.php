<?php

/*
 * Infrastruktura przy ofercie: najbliższe sklepy, żłobek, przedszkole, szkoły.
 * Punkty z OpenStreetMap przez Overpass API, raz w miesiącu dla obszaru wokół wyszukiwań
 * (jedno zapytanie o cały obszar, bez adresów ofert). Odległości liczy nasz OSRM.
 */
return [
    'overpass_url' => env('OVERPASS_URL', 'https://overpass-api.de/api/interpreter'),
    // Zapasowe serwery, gdy główny jest przeciążony. Gdy wszystkie zawiodą: scripts/pois-extract.sh (lokalna mapa).
    'overpass_urls' => ['https://overpass.kumi.systems/api/interpreter', 'https://maps.mail.ru/osm/tools/overpass/api/interpreter'],
    // Obszar: środki aktywnych wyszukiwań plus tyle km.
    'margin_km' => 25,
    // Szukamy najbliższych punktów w tym promieniu (km, w linii prostej).
    'search_km' => 8,
    // Ile sklepów pokazać (najbliższy z każdej sieci).
    'shops' => 5,
    // Duże sieci: przy ofercie najbliższy sklep każdej z nich, potem inne supermarkety.
    'brands' => ['Biedronka', 'Lidl', 'Kaufland', 'Auchan', 'Carrefour', 'Dino', 'Netto', 'Stokrotka', 'Aldi', 'Intermarché', 'Polomarket', 'Spar'],
    // Stałe miejsca przy każdej ofercie (adres szukany w OpenStreetMap), z czasem dojazdu autem.
    'places' => [
        'hospital' => ['name' => 'Szpital Miejski im. Jana Pawła II', 'address' => 'Rycerska 4, Rzeszów'],
    ],
    // Średnia prędkość pieszo (km/h) do czasu dojścia z długości trasy.
    'walk_kmh' => 4.8,
];
