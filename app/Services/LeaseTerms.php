<?php

namespace App\Services;

use App\Enums\DealType;
use App\Models\Listing;

/**
 * Warunki najmu: rodzaj umowy, minimalny okres, kaucja, zwierzęta, prowizja i kto wynajmuje.
 * Z danych portalu, a gdy ich brak, z opisu. Null, gdy ogłoszenie tego nie mówi.
 */
class LeaseTerms
{
    public const RENT_LABELS = ['Rodzaj umowy', 'Okres najmu', 'Kaucja', 'Dostępne od', 'Zwierzęta', 'Ogłoszeniodawca', 'Prowizja'];

    // Kolejność ważna: pierwsze dopasowanie wygrywa.
    private const CONTRACT = [
        'najem okazjonalny' => '/okazjonaln/u',
        'najem instytucjonalny' => '/instytucjonaln/u',
        'krótkoterminowy (na doby)' => '/na\s+doby|krótkoterm|krotkoterm|na\s+noc(?:e|leg)|wynajem\s+dobowy/u',
    ];

    private const NO_PETS = '/(?:bez|nie\s+akceptuj\w*|nie\s+przyjmuj\w*|nie\s+posiadaj\w*|nie\s+posiadając\w*|z\s+wyj[aą]tkiem)\s+(?:\w+\s+){0,3}zwierz|zwierz\w*\s+(?:nie\s+s[aą]\s+akceptow|nie\s+s[aą]\s+mile|nie\s+wchodz|nie\s+mog|niedozwolon|wykluczon|nie\s+akceptuj)|zakaz\w*\s+(?:\w+\s+){0,2}zwierz|nie\s+(?:\w+\s+){0,3}ze?\s+zwierz/u';

    private const PETS_OK = '/(?:akceptuj\w*|akceptowan\w*|dopuszczam\w*|przyjmuj\w*|zgod\w*\s+na|mile\s+widzian\w*|mog[aą]\s+by[cć])\s+(?:\w+\s+){0,2}zwierz|zwierz\w*\s+(?:\w+\s+){0,2}(?:mile\s+widzian|akceptow|dozwolon|s[aą]\s+ok|nie\s+s[aą]\s+problemem|do\s+uzgodnienia|po\s+uzgodnieniu|mo[zż]liw)|pet\s*friendly|przyjazn\w*\s+zwierz/u';

    /**
     * @return array{contract: ?string, min_period: ?string, deposit: ?int, deposit_note: ?string, free_from: ?string, pets: ?bool, commission: ?bool, advertiser: string}
     */
    public static function of(Listing $listing): array
    {
        $text = self::text($listing);
        $facts = ListingFacts::of($listing);
        $deposit = $facts['deposit'] ?? self::deposit($text);

        return [
            'contract' => self::firstMatch(self::CONTRACT, $text),
            'min_period' => self::minPeriod($text),
            'deposit' => $deposit,
            'deposit_note' => $deposit === null ? self::depositNote($text) : null,
            'free_from' => $facts['free_from'] ?? self::freeFrom($text),
            'pets' => self::pets($listing, $text),
            'commission' => self::commission($listing, $text),
            'advertiser' => self::advertiser($listing),
        ];
    }

    /**
     * Etykieta => tekst do panelu, promptu AI i asystenta. Przy sprzedaży tylko ogłoszeniodawca.
     *
     * @return array<string, string>
     */
    public static function labels(Listing $listing, bool $withUnknown = false): array
    {
        $terms = self::of($listing);
        $unknown = $withUnknown ? 'nie podano' : null;

        if ($listing->deal_type !== DealType::Rent) {
            return ['Ogłoszeniodawca' => $terms['advertiser']];
        }

        $labels = [
            'Rodzaj umowy' => $terms['contract'] ?? $unknown,
            'Okres najmu' => $terms['min_period'] ?? $unknown,
            'Kaucja' => $terms['deposit'] !== null ? number_format($terms['deposit'], 0, ',', ' ').' zł' : ($terms['deposit_note'] ?? $unknown),
            'Dostępne od' => $terms['free_from'] ?? $unknown,
            'Zwierzęta' => match ($terms['pets']) {
                true => 'można',
                false => 'nie',
                null => $unknown,
            },
            'Ogłoszeniodawca' => $terms['advertiser'],
            'Prowizja' => match ($terms['commission']) {
                true => 'tak, prowizja dla biura',
                false => 'bez prowizji',
                null => $listing->is_private_owner ? 'raczej nie (osoba prywatna)' : $unknown,
            },
        ];

        return array_filter($labels, fn (?string $value) => $value !== null);
    }

    /**
     * Krótko do listy ofert: "prywatnie · okazjonalny · bez zwierząt".
     */
    public static function short(Listing $listing): ?string
    {
        if ($listing->deal_type !== DealType::Rent) {
            return null;
        }

        $terms = self::of($listing);

        return collect([
            match ($listing->is_private_owner) {
                true => 'prywatnie',
                false => 'biuro',
                null => null,
            },
            match ($terms['contract']) {
                'najem okazjonalny' => 'okazjonalny',
                'najem instytucjonalny' => 'instytucjonalny',
                null => null,
                default => 'na doby',
            },
            $terms['min_period'] ? 'min. '.$terms['min_period'] : null,
            $terms['pets'] === false ? 'bez zwierząt' : null,
        ])->filter()->implode(' · ') ?: null;
    }

    /**
     * "osoba prywatna", "biuro: HOME", "deweloper: X", "biuro / firma".
     */
    public static function advertiser(Listing $listing): string
    {
        $raw = $listing->raw ?? [];
        $agency = is_array($raw['agency'] ?? null) ? $raw['agency'] : [];
        $name = trim((string) ($agency['name'] ?? (is_string($raw['seller'] ?? null) ? $raw['seller'] : '')));
        $developer = ($agency['type'] ?? null) === 'DEVELOPER';

        return match (true) {
            $listing->is_private_owner === true => 'osoba prywatna',
            $name !== '' => ($developer ? 'deweloper: ' : 'biuro: ').$name,
            $listing->is_private_owner === false => 'biuro / firma',
            default => 'nie wiadomo',
        };
    }

    private static function text(Listing $listing): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($listing->title.' '.$listing->description)));
    }

    /**
     * "12 mies.", "rok", "6 mies.", "długoterminowo".
     */
    private static function minPeriod(string $text): ?string
    {
        $intro = '(?:minimum|minimalnie|min\.?|co\s+najmniej|minimaln\w+\s+okres\w*(?:\s+najmu)?(?:\s+to|\s+wynosi)?|na\s+okres|na\s+czas|umow\w+\s+(?:najmu\s+)?(?:zawierana\s+|zawarta\s+)?na|najem\s+na|wynajem\s+na|wynajm\w+\s+na)\s*:?\s*(?:min\.?\s+|minimum\s+|co\s+najmniej\s+|okres\s+)?';

        if (preg_match('/'.$intro.'(\d{1,2})\s*(?:-\s*\d{1,2}\s*)?(mies|m-c|mc|rok|lat|lata)/u', $text, $match) === 1) {
            $count = (int) $match[1];

            if ($count === 0) {
                return null;
            }

            return str_starts_with($match[2], 'm') ? "{$count} mies." : ($count === 1 ? 'rok' : "{$count} lata");
        }

        if (preg_match('/'.$intro.'(?:jeden\s+|1\s+)?(rok|p[oó][lł]\s+roku)\b/u', $text, $match) === 1) {
            return str_starts_with($match[1], 'rok') ? 'rok' : '6 mies.';
        }

        return preg_match('/d[lł]ugoterm/u', $text) === 1 ? 'długoterminowo' : null;
    }

    private static function deposit(string $text): ?int
    {
        $amount = '(\d{1,2}[\s.]?\d{3}|\d{3,5})\s*(?:z[lł]|pln|,-|\b)';

        if (preg_match('/kaucj\w*\s*(?:zwrotn\w*\s*)?(?:jednorazow\w*\s*)?(?:w\s+wysoko[sś]ci|wynosi|to|:|-|–|=)?\s*'.$amount.'/u', $text, $match) === 1
            // "2500 zł – kaucja zwrotna". Bez myślnika to zwykle inna kwota przed słowem kaucja ("2400 zł kaucja do ustalenia").
            || preg_match('/'.$amount.'\s*(?:-|–|:)\s*kaucj/u', $text, $match) === 1) {
            $value = (int) preg_replace('/\D/', '', $match[1]);

            return $value >= 300 && $value <= 50000 ? $value : null;
        }

        return null;
    }

    /**
     * Kaucja bez kwoty: "równowartość 2 czynszów", "do ustalenia", "wymagana".
     */
    private static function depositNote(string $text): ?string
    {
        if (preg_match('/kaucj\w*[^.]{0,40}?(\d|jedn\w*|dw\w*|trzy\w*)[\s-]*(?:krotno[sś]\w*|miesi[eę]czn\w*|czynsz\w*|odst[eę]pn\w*|najm\w*)/u', $text, $match) === 1) {
            return 'równowartość '.match (true) {
                str_starts_with($match[1], 'jedn') => '1',
                str_starts_with($match[1], 'dw') => '2',
                str_starts_with($match[1], 'trz') => '3',
                default => $match[1],
            }.' mies. najmu';
        }

        if (preg_match('/kaucj\w*\s+(?:\w+\s+){0,2}do\s+(?:ustalenia|uzgodnienia)/u', $text) === 1) {
            return 'do ustalenia';
        }

        return preg_match('/kaucj/u', $text) === 1 ? 'jest, kwota nie podana' : null;
    }

    private static function freeFrom(string $text): ?string
    {
        if (preg_match('/(?:dost[eę]pn\w*|wolne|do\s+zamieszkania|wprowadzi[cć]\s+si[eę]\s+mo[zż]na)\s+(?:jest\s+)?(?:ju[zż]\s+)?od\s+(zaraz|\d{1,2}[.\/]\d{1,2}(?:[.\/]\d{2,4})?|\d{1,2}\s+[a-ząćęłńóśźż]{4,12}|pocz[aą]tku\s+[a-ząćęłńóśźż]{4,12}|po[lł]owy\s+[a-ząćęłńóśźż]{4,12}|[a-ząćęłńóśźż]{4,12}(?=\s|[.,!]))/u', $text, $match) !== 1) {
            return null;
        }

        $months = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia', 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
        $value = $match[1];

        // Samo słowo po "od" musi być miesiącem ("od grudnia"), inaczej to nie data ("od właściciela").
        if (preg_match('/^[a-ząćęłńóśźż]+$/u', $value) === 1 && $value !== 'zaraz' && ! in_array($value, ['grudnia', 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada'], true)) {
            return null;
        }

        if (preg_match('/^(?:\d{1,2}|pocz[aą]tku|po[lł]owy)\s+(\S+)$/u', $value, $parts) === 1 && ! in_array($parts[1], $months, true)) {
            return null;
        }

        return $value === 'zaraz' ? 'od zaraz' : $value;
    }

    private static function pets(Listing $listing, string $text): ?bool
    {
        foreach ((array) ($listing->raw['params'] ?? []) as $param) {
            if (($param['key'] ?? null) === 'pets') {
                $value = mb_strtolower((string) ($param['value']['key'] ?? ''));

                if (in_array($value, ['tak', 'yes'], true)) {
                    return true;
                }

                if (in_array($value, ['nie', 'no'], true)) {
                    return false;
                }
            }
        }

        return match (true) {
            preg_match(self::NO_PETS, $text) === 1 => false,
            preg_match(self::PETS_OK, $text) === 1 => true,
            default => null,
        };
    }

    private static function commission(Listing $listing, string $text): ?bool
    {
        return match (true) {
            preg_match('/(?:bez|brak|0\s*%?)\s+(?:\w+\s+){0,2}prowizj|prowizj\w*\s*(?::|-|–)?\s*(?:0|brak|nie\s+ma|nie\s+pobieramy)\b|nie\s+pobieram\w*\s+prowizj/u', $text) === 1 => false,
            preg_match('/prowizj|pobieramy\s+wynagrodzenie|wynagrodzeni\w*\s+(?:dla\s+)?(?:biura|agencji|po[sś]rednik)|op[lł]at\w*\s+(?:za\s+)?po[sś]rednictw/u', $text) === 1 => true,
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private static function firstMatch(array $patterns, string $text): ?string
    {
        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $label;
            }
        }

        return null;
    }
}
