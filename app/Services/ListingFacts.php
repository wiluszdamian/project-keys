<?php

namespace App\Services;

use App\Models\Listing;

/**
 * Najważniejsze fakty o mieszkaniu i budynku: z danych portalu (raw.details i parametry OLX),
 * a gdy ich brak, z opisu ogłoszenia. Wartość null, gdy nie wiadomo.
 */
class ListingFacts
{
    // Kolejność ważna: pierwsze dopasowanie wygrywa.
    private const HEATING = [
        'miejskie' => '/ogrzewani\w*\s+(z\s+)?(miejsk|sieci\s+miejsk|msc|mpec)|ciep\w*\s+(miejsk|systemow)|\bmpec\b|sie[cć]\s+ciep[lł]own|z\s+sieci\s+miejskiej|co\s+miejskie|centralne\s+miejskie/u',
        'gazowe' => '/ogrzewani\w*\s+gazow|piec\w*\s+gazow|kocio[lł]\w*\s+gazow|piecyk\w*\s+gazow|\bgazowe\s+ogrzewanie|kondensacyjn/u',
        'pompa ciepła' => '/pomp\w*\s+ciep[lł]a/u',
        'elektryczne' => '/ogrzewani\w*\s+elektryczn|grzejnik\w*\s+elektryczn|ogrzewanie\s+pod[lł]ogowe\s+elektryczne/u',
        'kominek' => '/kominek|kominkiem/u',
        'węglowe' => '/piec\w*\s+w[eę]glow|ogrzewani\w*\s+w[eę]glow|kocio[lł]\w*\s+w[eę]glow/u',
    ];

    // Wartości z portali ("urban", "gas", "Gazowe") na nasze nazwy.
    private const HEATING_FROM_PORTAL = [
        'urban' => 'miejskie', 'miejskie' => 'miejskie', 'sieć miejska' => 'miejskie', 'centralne' => 'miejskie',
        'gas' => 'gazowe', 'gazowe' => 'gazowe',
        'electrical' => 'elektryczne', 'elektryczne' => 'elektryczne',
        'heat_pump' => 'pompa ciepła', 'pompa ciepła' => 'pompa ciepła',
        'boiler_room' => 'kotłownia', 'kotłownia' => 'kotłownia',
        'tiled_stove' => 'piec kaflowy', 'other' => 'inne',
    ];

    /**
     * @return array{heating: ?string, build_year: ?int, building_floors: ?int, elevator: ?bool, balcony: ?bool, parking: array{type: string, price: ?string}|null, storage: array{type: string, price: ?string}|null, condition: ?string, deposit: ?int, free_from: ?string}
     */
    public static function of(Listing $listing): array
    {
        $details = (array) ($listing->raw['details'] ?? []);
        // Małe litery z polskimi znakami (wzorce mają warianty z ogonkami i bez).
        $text = mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($listing->title.' '.$listing->description)));

        return [
            'heating' => self::heating($details, $text),
            'build_year' => self::buildYear($details, $text),
            'building_floors' => isset($details['building_floors']) && is_numeric($details['building_floors']) ? (int) $details['building_floors'] : self::match('/(?:budynek|blok\w*|kamienic\w*)\s+(\d{1,2})[\s-]*pi[eę]tr/u', $text, int: true),
            'elevator' => match (true) {
                preg_match('/\bbez\s+windy|brak\s+windy/u', $text) === 1 => false,
                preg_match('/\bwind[aąyę]\b/u', $text) === 1 => true,
                default => null,
            },
            'balcony' => match (true) {
                preg_match('/\bbez\s+balkonu|brak\s+balkonu/u', $text) === 1 => false,
                preg_match('/balkon|loggi|taras/u', $text) === 1 => true,
                default => null,
            },
            'parking' => self::extra($text, filled($details['parking'] ?? null) ? mb_strtolower((string) $details['parking']) : null, [
                'garaż podziemny' => '/gara[zż]\w*\s+podziemn|hal\w*\s+gara[zż]|podziemn\w*\s+(hal\w*\s+)?gara[zż]/u',
                'garaż' => '/gara[zż]/u',
                'miejsce postojowe' => '/miejsc\w*\s+(postojow|parkingow)/u',
                'parking ogólnodostępny' => '/parking\w*\s+og[oó]lnodost[eę]pn|og[oó]lnodost[eę]pn\w*\s+parking/u',
                'parking' => '/parking/u',
            ], '/gara[zż]\w*|miejsc\w*\s+(?:postojow|parkingow)\w*|parking/u'),
            'storage' => self::extra($text, null, [
                'komórka lokatorska' => '/kom[oó]rk\w*\s+lokatorsk/u',
                'piwnica' => '/piwnic/u',
            ], '/kom[oó]rk\w*\s+lokatorsk\w*|piwnic\w*/u'),
            'condition' => self::condition($details),
            'deposit' => isset($details['deposit']) && is_numeric($details['deposit']) ? (int) $details['deposit'] : null,
            'free_from' => filled($details['free_from'] ?? null) ? (string) $details['free_from'] : null,
        ];
    }

    /**
     * Fakty jako etykieta => tekst, bez nieznanych. Do panelu, promptu AI i asystenta.
     *
     * @return array<string, string>
     */
    public static function labels(Listing $listing): array
    {
        $facts = self::of($listing);
        $yesNo = fn (?bool $value) => $value === null ? null : ($value ? 'tak' : 'nie');

        return array_filter([
            'Ogrzewanie' => $facts['heating'],
            'Rok budowy' => $facts['build_year'] ? (string) $facts['build_year'] : null,
            'Pięter w budynku' => $facts['building_floors'] ? (string) $facts['building_floors'] : null,
            'Winda' => $yesNo($facts['elevator']),
            'Balkon / taras' => $yesNo($facts['balcony']),
            'Garaż / parking' => self::extraText($facts['parking']),
            'Komórka / piwnica' => self::extraText($facts['storage']),
            'Stan' => $facts['condition'],
            'Kaucja' => $facts['deposit'] ? number_format($facts['deposit'], 0, ',', ' ').' zł' : null,
            'Dostępne od' => $facts['free_from'],
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Krótkie etykiety (badge) na stronie oferty: tekst => kolor Filamenta.
     * Zielony = dodatek w cenie, pomarańczowy = za dopłatą albo minus (bez windy, bez balkonu).
     *
     * @return array<string, string>
     */
    public static function badges(Listing $listing): array
    {
        $facts = self::of($listing);
        $badges = [];

        foreach (['parking', 'storage'] as $key) {
            if ($facts[$key] !== null) {
                $badges[ucfirst((string) self::extraText($facts[$key]))] = match ($facts[$key]['price']) {
                    'included' => 'success',
                    'extra' => 'warning',
                    default => 'gray',
                };
            }
        }

        if ($facts['heating']) {
            $badges['Ogrzewanie '.$facts['heating']] = 'gray';
        }

        if ($facts['build_year']) {
            $badges['Budynek z '.$facts['build_year']] = 'gray';
        }

        if ($facts['elevator'] !== null) {
            $badges[$facts['elevator'] ? 'Winda' : 'Bez windy'] = $facts['elevator'] ? 'gray' : 'warning';
        }

        if ($facts['balcony'] !== null) {
            $badges[$facts['balcony'] ? 'Balkon / taras' : 'Bez balkonu'] = $facts['balcony'] ? 'gray' : 'warning';
        }

        return $badges;
    }

    /**
     * "garaż w cenie", "komórka lokatorska za dopłatą", "piwnica".
     *
     * @param  array{type: string, price: ?string}|null  $extra
     */
    private static function extraText(?array $extra): ?string
    {
        if ($extra === null) {
            return null;
        }

        return $extra['type'].match ($extra['price']) {
            'included' => ' w cenie',
            'extra' => ' za dopłatą',
            default => '',
        };
    }

    /**
     * Dodatek do mieszkania (garaż, komórka) i czy jest w cenie. Cenę sprawdzamy tylko w zdaniach
     * (albo punktach listy), w których jest wzmianka o tym dodatku.
     *
     * @param  array<string, string>  $types  [nazwa => wzorzec], pierwsze dopasowanie wygrywa
     * @return array{type: string, price: ?string}|null
     */
    private static function extra(string $text, ?string $fromPortal, array $types, string $mention): ?array
    {
        $type = $fromPortal;

        if ($type === null) {
            foreach ($types as $label => $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $type = $label;
                    break;
                }
            }
        }

        if ($type === null) {
            return null;
        }

        // Zdania i punkty listy. Myślnik dzieli punkty, chyba że dalej jest cena ("komórka - 12 000 zł").
        $parts = preg_split('/(?<=[.!?;])\s+|\s+[-–•]\s+(?![\d+]|za\s|w\s+cenie|dodatk|p[lł]at|cen|gratis)/u', $text) ?: [$text];
        $context = implode(' ', array_filter($parts, fn (string $part) => preg_match($mention, $part) === 1));

        $price = match (true) {
            preg_match('/za\s+dop[lł]at|dodatkowo\s+p[lł]atn|p[lł]atn\w*\s+dodatkowo|mo[zż]liwo[sś][cć]\s+(do)?(kupienia|zakupu|wynaj[eę]cia)|do\s+(do)?kupienia|nie\s+(jest\s+)?wliczon|osobn\w*\s+(ksi[eę]g|cen|op[lł]at)|\+\s*\d[\d\s]*z[lł]|\d[\d\s]{2,}\s*z[lł]|\d+\s*tys/u', $context) === 1 => 'extra',
            preg_match('/w\s+cenie|wliczon|bez\s+dop[lł]at|gratis|w\s+komplecie|przynale[zż]|nale[zż]y\s+do\s+mieszkania|do\s+mieszkania\s+(nale[zż]y|jest)|wraz\s+z/u', $context) === 1 => 'included',
            default => null,
        };

        return ['type' => $type, 'price' => $price];
    }

    private static function heating(array $details, string $text): ?string
    {
        $fromPortal = mb_strtolower(trim((string) ($details['heating'] ?? '')));

        if ($fromPortal !== '') {
            return self::HEATING_FROM_PORTAL[$fromPortal] ?? $fromPortal;
        }

        foreach (self::HEATING as $label => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $label;
            }
        }

        return null;
    }

    private static function buildYear(array $details, string $text): ?int
    {
        $year = $details['build_year'] ?? null;

        if (is_numeric($year) && $year >= 1850 && $year <= (int) date('Y') + 3) {
            return (int) $year;
        }

        $match = self::match('/(?:rok\w*\s+budowy|rok\s+oddania|wybudowan\w*\s+w(?:\s+roku)?|oddan\w*\s+do\s+u[zż]ytku\s+w(?:\s+roku)?|(?:blok\w*|budyn\w*|kamienic\w*|budownictw\w*)\s+z(?:\s+roku)?)\s*:?\s*(1[89]\d{2}|20\d{2})/u', $text, int: true);

        return $match !== null && $match <= (int) date('Y') + 3 ? $match : null;
    }

    private static function condition(array $details): ?string
    {
        $value = trim((string) ($details['condition'] ?? ''));

        return match ($value) {
            '' => null,
            'ready_to_use' => 'do zamieszkania',
            'to_completion' => 'do wykończenia',
            'to_renovation' => 'do remontu',
            default => $value,
        };
    }

    private static function match(string $pattern, string $text, bool $int = false): int|string|null
    {
        if (preg_match($pattern, $text, $match) !== 1) {
            return null;
        }

        return $int ? (int) $match[1] : $match[1];
    }
}
