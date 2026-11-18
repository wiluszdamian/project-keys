<?php

use App\Models\Listing;
use App\Services\ImageHasher;
use App\Services\RiskDetector;

it('wykrywa typowe zwroty z fałszywych ogłoszeń', function () {
    $listing = Listing::factory()->create(['description' => 'Jestem obecnie za granicą, po wpłacie kaucji przed obejrzeniem wyślę klucze kurierem.']);

    $messages = collect(app(RiskDetector::class)->detect($listing))->pluck('message')->implode(' ');

    expect($messages)->toContain('właściciel za granicą')
        ->toContain('kaucja przed obejrzeniem')
        ->toContain('klucze wysyłane pocztą lub kurierem');
});

it('zwykłe ogłoszenie nie ma sygnałów ryzyka', function () {
    $listing = Listing::factory()->create(['description' => 'Mieszkanie 3 pokoje, balkon, kaucja 3000 zł, oglądanie po umówieniu.']);

    expect(app(RiskDetector::class)->detect($listing))->toBe([]);
});

it('cena dużo poniżej mediany w okolicy to sygnał ryzyka', function () {
    Listing::factory()->count(12)->create(['city' => 'Rzeszów', 'district' => 'Projektant', 'deal_type' => 'rent', 'price_per_m2' => 50, 'last_seen_at' => now()]);
    $cheap = Listing::factory()->create(['city' => 'Rzeszów', 'district' => 'Projektant', 'deal_type' => 'rent', 'price' => 1200, 'area' => 60, 'price_per_m2' => 20, 'description' => 'Ładne mieszkanie.']);

    expect(app(RiskDetector::class)->detect($cheap))->toHaveCount(1)
        ->and(app(RiskDetector::class)->detect($cheap)[0]['message'])->toContain('o 60% niższa niż mediana');
});

it('to samo zdjęcie w ofercie z innego miasta to sygnał ryzyka, a duplikat z innego portalu nie', function () {
    $original = Listing::factory()->create(['city' => 'Kraków', 'price' => 3000, 'image_hash' => 'f0f0f0f0a5a5a5a5', 'title' => 'Mieszkanie w Krakowie']);
    $copy = Listing::factory()->create(['city' => 'Rzeszów', 'price' => 1500, 'image_hash' => 'f0f0f0f0a5a5a5a4', 'description' => 'Tanio.']);
    Listing::factory()->create(['city' => 'Rzeszów', 'price' => 2800, 'image_hash' => '0123456789abcdef']);
    $sameOffer = Listing::factory()->create(['city' => 'Rzeszów', 'price' => 2850, 'image_hash' => '0123456789abcdef', 'description' => 'Ta sama oferta z innego portalu.']);

    expect(app(RiskDetector::class)->detect($copy)[0])->toMatchArray(['code' => 'reused_photo', 'listing_id' => $original->id])
        ->and(app(RiskDetector::class)->detect($sameOffer))->toBe([]);
});

it('hash zdjęcia jest taki sam po przeskalowaniu', function () {
    $image = imagecreatetruecolor(300, 200);
    for ($x = 0; $x < 300; $x++) {
        imageline($image, $x, 0, $x, 200, imagecolorallocate($image, (int) ($x * 0.8), 120, 255 - (int) ($x * 0.8)));
    }
    ob_start();
    imagejpeg($image, null, 90);
    $big = ob_get_clean();

    $small = imagescale($image, 150, 100);
    ob_start();
    imagejpeg($small, null, 60);
    $smaller = ob_get_clean();

    $hasher = app(ImageHasher::class);

    expect(ImageHasher::distance($hasher->hash($big), $hasher->hash($smaller)))->toBeLessThanOrEqual(4)
        ->and($hasher->hash($big))->toHaveLength(16);
});
