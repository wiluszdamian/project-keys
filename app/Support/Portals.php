<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Nazwy i kolory portali do panelu i wiadomości. Lista włączonych portali: config('scrapers.portals').
 */
class Portals
{
    public const LABELS = [
        'otodom' => 'Otodom',
        'olx' => 'OLX',
        'rzeszowiak' => 'Rzeszowiak',
        'morizon' => 'Morizon',
        'gratka' => 'Gratka',
        'nieruchomosci_online' => 'Nieruchomości-online',
        'domiporta' => 'Domiporta',
        'adresowo' => 'Adresowo',
        'stoprocent' => 'Stoprocent',
        'rzeszowskie' => 'Rzeszowskie Nieruchomości',
        'pierwsze_pietro' => 'Pierwsze Piętro',
    ];

    // Kolory Filamenta w panelu.
    private const COLORS = [
        'otodom' => 'success',
        'olx' => 'info',
        'rzeszowiak' => 'warning',
        'morizon' => 'danger',
        'gratka' => 'primary',
        'nieruchomosci_online' => 'success',
        'domiporta' => 'info',
        'adresowo' => 'warning',
        'stoprocent' => 'danger',
        'rzeszowskie' => 'primary',
        'pierwsze_pietro' => 'info',
    ];

    /**
     * Portale, które potrzebują mapy lokalizacji (config scrapers.{portal}.locations).
     */
    public const WITH_LOCATIONS = ['otodom', 'morizon', 'gratka', 'domiporta', 'adresowo'];

    /**
     * Portal nie zna miejscowości z wyszukiwania (brak wpisu w mapie lokalizacji).
     */
    public static function missingLocation(string $portal, string $city): bool
    {
        return in_array($portal, self::WITH_LOCATIONS, true)
            && ! array_key_exists(Str::slug($city), config("scrapers.{$portal}.locations", []));
    }

    public static function label(?string $portal): string
    {
        return self::LABELS[$portal] ?? (string) $portal;
    }

    public static function color(?string $portal): string
    {
        return self::COLORS[$portal] ?? 'gray';
    }

    /**
     * Włączone portale jako opcje formularza.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(config('scrapers.portals'))->mapWithKeys(fn (string $portal) => [$portal => self::label($portal)])->all();
    }

    /**
     * Wszystkie znane portale (także te, które już wyłączono), np. do filtrów listy ofert.
     *
     * @return array<string, string>
     */
    public static function allOptions(): array
    {
        return array_intersect_key(self::LABELS, array_flip(array_unique([...config('scrapers.portals'), ...array_keys(self::LABELS)])));
    }
}
