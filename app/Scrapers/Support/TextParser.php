<?php

namespace App\Scrapers\Support;

use Illuminate\Support\Str;

/**
 * Wyciąganie danych z tekstu ogłoszeń, gdy portal nie ma osobnych pól.
 */
class TextParser
{
    private const ROOM_WORDS = [
        'kawalerk' => 1, 'jednopokoj' => 1, 'dwupokoj' => 2, 'trzypokoj' => 3,
        'czteropokoj' => 4, 'pieciopokoj' => 5,
    ];

    /**
     * Liczba z tekstu typu "72,5", "1 250 000", "559000 zł".
     */
    public static function number(?string $text): ?float
    {
        if ($text === null || ! preg_match('/\d[\d\s\x{00A0}]*(?:[.,]\d+)?/u', $text, $match)) {
            return null;
        }

        return (float) str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($match[0]));
    }

    public static function int(?string $text): ?int
    {
        $number = self::number($text);

        return $number === null ? null : (int) round($number);
    }

    /**
     * Metraż: "63,50 m2", "37 m²", "75m2". Pierwsza sensowna wartość (15 do 500 m²).
     */
    public static function area(string $text): ?float
    {
        preg_match_all('/(\d{2,3}(?:[.,]\d{1,2})?)\s*(?:m2|m²|mkw|m\.kw|metr)/iu', $text, $matches);

        foreach ($matches[1] as $value) {
            $area = (float) str_replace(',', '.', $value);

            if ($area >= 15 && $area <= 500) {
                return $area;
            }
        }

        return null;
    }

    /**
     * Pokoje: "kawalerka", "3-pokojowe", "4 pokoje", "trzypokojowe".
     */
    public static function rooms(string $text): ?int
    {
        $ascii = self::normalize($text);

        if (preg_match('/\b(\d)\s*-?\s*(?:pok|pokoj|pokoje|pokojowe|pokojowy|pokojow)\b/u', $ascii, $match)) {
            return (int) $match[1];
        }

        foreach (self::ROOM_WORDS as $word => $rooms) {
            if (str_contains($ascii, $word)) {
                return $rooms;
            }
        }

        return null;
    }

    /**
     * Czy tekst wspomina miejscowość w dowolnej odmianie: "Rzeszów", "w Rzeszowie", "Rudnej Małej".
     */
    public static function mentionsPlace(string $text, string $place): bool
    {
        $words = array_filter(explode(' ', self::normalize($place)));

        if ($words === []) {
            return false;
        }

        // Rdzeń słowa bez końcówki odmiany, np. "boguchwala" -> "boguchwa", "rudna" -> "rud".
        $pattern = collect($words)
            ->map(fn (string $word) => preg_quote(mb_substr($word, 0, max(3, mb_strlen($word) - 2)), '/').'\w*')
            ->implode('\s+');

        return (bool) preg_match('/\b'.$pattern.'\b/u', self::normalize($text));
    }

    /**
     * HTML opisu na zwykły tekst z podziałem na linie.
     */
    public static function htmlToText(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>|<\/p>|<\/li>/i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);

        return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text));
    }

    public static function normalize(string $text): string
    {
        return Str::of($text)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    /**
     * Obiekt JSON zaczynający się zaraz po znaczniku, np. "buildCachedProps(" -> {...}.
     */
    public static function jsonAfter(string $text, string $marker): ?array
    {
        $start = strpos($text, $marker);

        if ($start === false || ($start = strpos($text, '{', $start)) === false) {
            return null;
        }

        $depth = 0;
        $inString = false;

        for ($i = $start, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                $decoded = json_decode(substr($text, $start, $i - $start + 1), true);

                return is_array($decoded) ? $decoded : null;
            }
        }

        return null;
    }
}
