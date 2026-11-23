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

    // Nie da się od razu zamieszkać: brak podłóg, kuchni albo łazienki, albo potrzebny remont.
    private const NOT_READY = '/stan(ie)?\s+deweloperski|stan(ie)?\s+surowy|surowy\s+(zamknięty|otwarty)|do\s+wykończenia|do\s+(generalnego\s+|kapitalnego\s+|całkowitego\s+|gruntownego\s+)?remontu|(?<!(?<!\p{L})nie\s)wymaga\s+(generalnego\s+|kapitalnego\s+|gruntownego\s+|całkowitego\s+)?remontu|bez\s+(podłóg|kuchni)|do\s+aranżacji\s+według/iu';

    private const READY = '/gotowe\s+do\s+(zamieszkania|wprowadzenia)|do\s+zamieszkania\s+od\s+zaraz|(można|możliwość)\s+(się\s+)?(od\s+razu\s+)?wprowadzi|do\s+wprowadzenia|nie\s+wymaga\s+(żadnego\s+)?(remontu|nakładów)|po\s+(generalnym\s+|kapitalnym\s+|całkowitym\s+)?remoncie|wykończone|urządzone/iu';

    private const VISUALIZATIONS = '/wizualizac|zdjęcia\s+(są\s+)?poglądowe|zdjęcia\s+przedstawiają\s+wizualizac|\brender/iu';

    public function __construct(
        public ?string $market,
        public ?bool $furnished,
        public bool $visualizations,
        public ?bool $ready = null,
    ) {}

    public static function of(ListingData|Listing $listing): self
    {
        $raw = $listing->raw ?? [];
        $text = trim(($listing->title ?? '').' '.($listing->description ?? ''));
        $portal = $listing->portal;
        $externalId = $listing instanceof Listing ? (string) $listing->external_id : $listing->externalId;

        $furnished = self::furnished($raw, $text);

        return new self(
            market: self::market($raw, $portal, $externalId),
            furnished: $furnished,
            visualizations: preg_match(self::VISUALIZATIONS, $text) === 1,
            ready: self::ready($raw, $text, $furnished),
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

    /**
     * Czy da się od razu zamieszkać (podłogi, kuchnia, łazienka). Meble nie są potrzebne.
     * Stan deweloperski albo remont w danych portalu lub opisie wygrywa z resztą, bo opis bywa dokładniejszy.
     */
    private static function ready(array $raw, string $text, ?bool $furnished): ?bool
    {
        $condition = mb_strtolower(trim((string) Arr::get($raw, 'details.condition')));
        $fromPortal = match (true) {
            $condition === '' => null,
            in_array($condition, ['to_completion', 'to_renovation'], true),
            preg_match('/deweloper|developer|wykończeni|remont|surow/u', $condition) === 1 => false,
            in_array($condition, ['ready_to_use'], true),
            preg_match('/zamieszkani|bardzo dobry|dobry|idealny|po remoncie|wykończon/u', $condition) === 1 => true,
            default => null,
        };

        if ($fromPortal === false || preg_match(self::NOT_READY, $text) === 1) {
            return false;
        }

        if ($fromPortal === true || $furnished === true || preg_match(self::READY, $text) === 1) {
            return true;
        }

        return null;
    }

    public function readyLabel(): string
    {
        return match ($this->ready) {
            true => 'tak',
            false => 'nie',
            null => 'nie wiadomo',
        };
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
