<?php

namespace App\Services\Products;

/**
 * Cena z tekstu: "4 999,00 zł", "4999.00", "1.299,99 PLN", "1,299.99".
 */
class PriceParser
{
    public const MIN = 0.01;

    public const MAX = 10_000_000;

    public static function parse(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return self::valid((float) $value);
        }

        if (! is_string($value)) {
            return null;
        }

        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}", ' ', "'"], '', $value);

        if (preg_match('/\d[\d.,]*/', $text, $match) !== 1) {
            return null;
        }

        $number = rtrim($match[0], '.,');
        $comma = strrpos($number, ',');
        $dot = strrpos($number, '.');

        if ($comma !== false && $dot !== false) {
            // Ostatni separator to część dziesiętna, drugi oddziela tysiące.
            $decimal = $comma > $dot ? ',' : '.';
            $number = str_replace($decimal === ',' ? '.' : ',', '', $number);
            $number = str_replace(',', '.', $number);
        } elseif ($comma !== false) {
            $number = preg_match('/^\d{1,3}(,\d{3})+$/', $number) === 1 ? str_replace(',', '', $number) : str_replace(',', '.', $number);
        } elseif ($dot !== false && preg_match('/^\d{1,3}(\.\d{3})+$/', $number) === 1) {
            $number = str_replace('.', '', $number);
        }

        if (substr_count($number, '.') > 1 || ! is_numeric($number)) {
            return null;
        }

        return self::valid((float) $number);
    }

    private static function valid(float $price): ?float
    {
        return $price >= self::MIN && $price <= self::MAX ? round($price, 2) : null;
    }
}
