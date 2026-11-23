<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\RcnTransaction;
use App\Scrapers\Data\ListingData;
use Illuminate\Support\Arr;

/**
 * Cechy oferty, których portale nie podają w jednym miejscu: rynek, umeblowanie, wizualizacje.
 * Najpierw dane z portalu (parametry, strona ogłoszenia), potem tytuł i opis.
 * Null znaczy "nie wiadomo".
 */
final readonly class ListingFeatures
{
    // Najpierw zaprzeczenia, bo "nieumeblowane" zawiera "umeblowane". "nie" jako osobne słowo, bo "mieszkanie umeblowane" też ma w sobie "nie umeblowane".
    private const NOT_FURNISHED = '/(?<!\p{L})nie\s*umeblowan|(?<!\p{L})nie\s+(jest|będzie)\s+umeblowan|bez\s+mebli|bez\s+umeblowania|bez\s+wyposażenia|meble\s+nie\s+(są|zostają|wchodzą)|stan(ie)?\s+deweloperski|do\s+wykończenia/iu';

    private const FURNISHED = '/umeblowan|z\s+meblami|meble\s+(w\s+cenie|zostają|pozostają|kuchenne\s+i\s+pokojowe)|pozostaj[ąe]\s+(w\s+nim\s+)?meble|w\s+pełni\s+wyposażon|kompletnie\s+wyposażon|pełne\s+wyposażenie|wyposażenie\s+w\s+cenie|wraz\s+z\s+(meblami|wyposażeniem)|meble\s+na\s+wymiar|zabudow[aą]\s+meblow/iu';

    private const VISUALIZATIONS = '/wizualizac|zdjęcia\s+(są\s+)?poglądowe|zdjęcia\s+przedstawiają\s+wizualizac|\brender/iu';

    public function __construct(
        public ?string $market,
        public ?bool $furnished,
        public bool $visualizations,
    ) {}

    public static function of(ListingData|Listing $listing): self
    {
        $raw = $listing->raw ?? [];
        $text = trim(($listing->title ?? '').' '.($listing->description ?? ''));
        $portal = $listing->portal;
        $externalId = $listing instanceof Listing ? (string) $listing->external_id : $listing->externalId;

        return new self(
            market: self::market($raw, $portal, $externalId),
            furnished: self::furnished($raw, $text),
            visualizations: preg_match(self::VISUALIZATIONS, $text) === 1,
        );
    }

    /**
     * RcnTransaction::PRIMARY, RcnTransaction::SECONDARY albo null.
     */
    public static function market(array $raw, string $portal, ?string $externalId = null): ?string
    {
        $value = $raw['market']
            ?? Arr::get($raw, 'details.market')
            ?? collect($raw['params'] ?? [])->firstWhere('key', 'market')['value']['key']
            ?? null;

        $market = match (mb_strtolower(trim((string) $value))) {
            'primary', 'pierwotny', 'rynek pierwotny' => RcnTransaction::PRIMARY,
            'secondary', 'wtórny', 'wtorny', 'rynek wtórny' => RcnTransaction::SECONDARY,
            default => null,
        };

        if ($market !== null) {
            return $market;
        }

        // Otodom: oferta w inwestycji dewelopera.
        if ($portal === 'otodom' && ! empty($raw['developmentId'])) {
            return RcnTransaction::PRIMARY;
        }

        if ($portal === 'domiporta' && str_starts_with((string) $externalId, 'nowe-')) {
            return RcnTransaction::PRIMARY;
        }

        return null;
    }

    private static function furnished(array $raw, string $text): ?bool
    {
        // OLX: parametr "Umeblowane" (yes / no).
        $olx = collect($raw['params'] ?? [])->firstWhere('key', 'furniture')['value']['key'] ?? null;

        if (in_array($olx, ['yes', 'no'], true)) {
            return $olx === 'yes';
        }

        // Ze strony ogłoszenia (Otodom: wyposażenie zawiera meble).
        $details = $raw['details']['furnished'] ?? null;

        if (is_bool($details)) {
            return $details;
        }

        if ($text === '') {
            return null;
        }

        if (preg_match(self::NOT_FURNISHED, $text) === 1) {
            return false;
        }

        return preg_match(self::FURNISHED, $text) === 1 ? true : null;
    }

    public function marketLabel(): string
    {
        return match ($this->market) {
            RcnTransaction::PRIMARY => 'pierwotny',
            RcnTransaction::SECONDARY => 'wtórny',
            default => 'nie wiadomo',
        };
    }

    public function furnishedLabel(): string
    {
        return match ($this->furnished) {
            true => 'tak',
            false => 'nie',
            null => 'nie wiadomo',
        };
    }
}
