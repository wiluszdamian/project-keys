<?php

namespace App\Services;

use App\Models\Listing;

/**
 * Szuka tej samej oferty na innym portalu.
 *
 * 1. Dokładnie: Morizon i Gratka mają to samo raw.network_id, a ogłoszenie OLX skopiowane
 *    z Otodom ma w raw.external_url adres oferty Otodom.
 * 2. Przybliżenie: to samo miasto i typ transakcji, cena różna o mniej niż 2%,
 *    metraż o mniej niż 1 m2, ta sama liczba pokoi (gdy znana), oferta widziana w ciągu 7 dni.
 */
class DuplicateDetector
{
    public function findOriginal(Listing $listing): ?Listing
    {
        return $this->byNetworkId($listing) ?? $this->byExternalUrl($listing) ?? $this->bySimilarity($listing);
    }

    /**
     * Morizon i Gratka podają to samo id oferty.
     */
    private function byNetworkId(Listing $listing): ?Listing
    {
        $id = $listing->raw['network_id'] ?? null;

        return $id === null ? null : $this->candidates($listing)->where('raw->network_id', (string) $id)->orderBy('id')->first();
    }

    private function byExternalUrl(Listing $listing): ?Listing
    {
        $externalUrl = $listing->raw['external_url'] ?? null;

        if ($externalUrl) {
            $original = $this->candidates($listing)
                ->where('url', strtok($externalUrl, '?#'))
                ->first();

            if ($original) {
                return $original;
            }
        }

        // Odwrotnie: kopia z OLX zapisana wcześniej niż oferta z Otodom.
        return $this->candidates($listing)
            ->where('raw->external_url', 'like', $listing->url.'%')
            ->first();
    }

    private function bySimilarity(Listing $listing): ?Listing
    {
        if ($listing->price === null || $listing->area === null || $listing->city === null) {
            return null;
        }

        $config = config('scrapers.duplicates');
        $priceTolerance = $listing->price * $config['price_tolerance'];
        $area = (float) $listing->area;

        return $this->candidates($listing)
            ->where('deal_type', $listing->deal_type)
            ->where('city', $listing->city)
            // Cena to liczba całkowita, więc granice też. Różnica nadal mniejsza niż 2%.
            ->where('price', '>', (int) floor($listing->price - $priceTolerance))
            ->where('price', '<', (int) ceil($listing->price + $priceTolerance))
            ->where('area', '>', $area - $config['area_tolerance'])
            ->where('area', '<', $area + $config['area_tolerance'])
            // Przy wielu portalach sama cena i metraż mylą się częściej: liczba pokoi musi się zgadzać, gdy obie ją znają.
            ->when($listing->rooms !== null, fn ($query) => $query->where(fn ($query) => $query->whereNull('rooms')->orWhere('rooms', $listing->rooms)))
            ->where('last_seen_at', '>=', now()->subDays($config['days']))
            ->orderBy('first_seen_at')
            ->first();
    }

    /**
     * Oferty z innych portali, które same nie są duplikatem.
     */
    private function candidates(Listing $listing)
    {
        return Listing::query()
            ->where('portal', '!=', $listing->portal)
            ->whereNull('duplicate_of_id')
            ->where('id', '!=', $listing->id);
    }
}
