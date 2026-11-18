<?php

namespace App\Services;

use App\Enums\DealType;
use App\Models\Listing;
use App\Scrapers\Support\TextParser;
use Illuminate\Support\Number;

/**
 * Sygnały możliwego oszustwa: to samo zdjęcie w innej ofercie, cena dużo poniżej rynku,
 * typowe zwroty z fałszywych ogłoszeń (przedpłata, klucze kurierem, właściciel za granicą).
 */
class RiskDetector
{
    // Zwroty po normalizacji (bez polskich znaków, małe litery).
    private const PHRASES = [
        'przedplat' => 'prośba o przedpłatę',
        'zaliczk\w* (przed|bez) (obejrz|ogladan|spotkan)' => 'zaliczka przed obejrzeniem',
        'kaucj\w* (przed|bez) (obejrz|ogladan|spotkan)' => 'kaucja przed obejrzeniem',
        '(wysl|przesl|nadam)\w* (\w+ )?klucz' => 'klucze wysyłane pocztą lub kurierem',
        'klucz\w* (kurier|poczt|paczkomat)' => 'klucze wysyłane pocztą lub kurierem',
        '(jestem|przebywam|mieszkam|pracuje) (obecnie )?(za granica|zagranica|w (anglii|niemczech|norwegii|holandii|irlandii|hiszpanii|usa))' => 'właściciel za granicą',
        'western union|moneygram|bitcoin|kryptowalut' => 'nietypowa forma płatności',
        '(kontakt|pisz|prosze pisac) (tylko|wylacznie) (mail|e mail|whatsapp|przez whatsapp|telegram)' => 'kontakt tylko mailowo lub przez komunikator',
        'airbnb (pobier|obsluguj|posredniczy)|przez airbnb' => 'płatność przez Airbnb lub inną platformę',
    ];

    private const PRICE_RATIO = 0.6;

    private const PHOTO_DISTANCE = 4;

    public function __construct(private MarketStats $market) {}

    /**
     * @return list<array{code: string, message: string}>
     */
    public function detect(Listing $listing): array
    {
        return array_values(array_filter([
            ...$this->phrases($listing),
            $this->belowMarket($listing),
            $this->reusedPhoto($listing),
        ]));
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    private function phrases(Listing $listing): array
    {
        $text = TextParser::normalize($listing->title.' '.$listing->description);
        $found = [];

        foreach (self::PHRASES as $pattern => $message) {
            if (preg_match('/\b('.$pattern.')/', $text)) {
                $found[$message] = ['code' => 'phrase', 'message' => "W opisie: {$message}."];
            }
        }

        return array_values($found);
    }

    private function belowMarket(Listing $listing): ?array
    {
        if (! $listing->price_per_m2) {
            return null;
        }

        $market = $this->market->forListing($listing);
        $median = $market['median_price_per_m2'];

        if (! $market['enough_data'] || ! $median || $listing->price_per_m2 >= $median * self::PRICE_RATIO) {
            return null;
        }

        $percent = (int) round((1 - $listing->price_per_m2 / $median) * 100);
        $unit = $listing->deal_type === DealType::Rent ? ' zł/m² miesięcznie' : ' zł/m²';

        return [
            'code' => 'below_market',
            'message' => "Cena za m² o {$percent}% niższa niż mediana ({$market['scope']}): "
                .Number::format($listing->price_per_m2, locale: 'pl').' wobec '.Number::format($median, locale: 'pl').$unit.'.',
        ];
    }

    /**
     * To samo zdjęcie w ofercie z innego miasta, innego typu albo z ceną różną o ponad 30%.
     * Ta sama oferta na innym portalu (podobna cena, to samo miasto) to duplikat, nie oszustwo.
     */
    private function reusedPhoto(Listing $listing): ?array
    {
        if (! $listing->image_hash) {
            return null;
        }

        $group = array_filter([$listing->id, $listing->duplicate_of_id]);

        $other = Listing::query()
            ->whereNotNull('image_hash')
            ->where('image_hash', '!=', '')
            ->whereNotIn('id', $group)
            ->where(fn ($query) => $query->whereNull('duplicate_of_id')->orWhereNotIn('duplicate_of_id', $group))
            ->where(fn ($query) => $query
                ->where('image_hash', $listing->image_hash)
                ->orWhere('image_hash', 'like', substr($listing->image_hash, 0, 4).'%')
                ->orWhere('image_hash', 'like', '%'.substr($listing->image_hash, -4)))
            ->limit(50)
            ->get()
            ->first(fn (Listing $candidate) => ImageHasher::distance($candidate->image_hash, $listing->image_hash) <= self::PHOTO_DISTANCE
                && ($candidate->city !== $listing->city
                    || $candidate->deal_type !== $listing->deal_type
                    || ($candidate->price && $listing->price && abs($candidate->price - $listing->price) / max($candidate->price, $listing->price) > 0.3)));

        if (! $other) {
            return null;
        }

        return [
            'code' => 'reused_photo',
            'message' => "To samo zdjęcie jest w innej ofercie: \"{$other->title}\" ({$other->city}, "
                .($other->price ? Number::format($other->price, locale: 'pl').' zł' : 'cena nieznana').", {$other->deal_type->getLabel()}).",
            'listing_id' => $other->id,
        ];
    }
}
