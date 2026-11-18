<?php

namespace App\Scrapers\Concerns;

use App\Scrapers\Exceptions\ScraperException;
use App\Support\Portals;
use Illuminate\Support\Str;

/**
 * Portale, które w adresie potrzebują własnej ścieżki lokalizacji (mapa w config, edytowalna w Ustawieniach).
 */
trait ResolvesLocation
{
    protected function location(string $city): string
    {
        $slug = Str::slug($city);
        $location = config("scrapers.{$this->name()}.locations.{$slug}");

        if ($location === null) {
            throw new ScraperException(Portals::label($this->name()).": brak lokalizacji dla miasta \"{$city}\". Dodaj ją w panelu: Ustawienia → Lokalizacje ({$slug}).");
        }

        return $location;
    }
}
