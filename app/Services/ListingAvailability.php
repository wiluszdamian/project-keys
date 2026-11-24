<?php

namespace App\Services;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Notifications\PanelNotifier;
use App\Scrapers\HttpFetcher;

/**
 * Czy ogłoszenie wciąż jest na portalu. Pobiera stronę ogłoszenia i rozpoznaje zdjętą ofertę po kodzie
 * (404, 410), przekierowaniu na stronę główną lub listę ofert albo komunikacie portalu ("archiwalne", "wygasło").
 */
class ListingAvailability
{
    // Statusy, przy których właściciel dostaje powiadomienie, że oferta zniknęła.
    private const WATCHED = [ListingStatus::Favorite, ListingStatus::Contacted, ListingStatus::Viewing];

    public function __construct(private HttpFetcher $fetcher, private PanelNotifier $panel) {}

    /**
     * Sprawdza ofertę i zapisuje wynik. Zwraca powód, gdy oferta jest nieaktywna, albo null.
     * Błąd sieci, 5xx albo blokada portalu rzuca wyjątek i niczego nie zmienia.
     */
    public function check(Listing $listing): ?string
    {
        $reason = self::classify($listing, $this->fetcher->probe($listing->portal, $listing->url));

        $listing->forceFill(['availability_checked_at' => now()])->save();

        if ($reason !== null) {
            $this->markInactive($listing, $reason);
        }

        return $reason;
    }

    public function markInactive(Listing $listing, string $reason): void
    {
        if ($listing->inactive_at !== null) {
            return;
        }

        $listing->forceFill([
            'inactive_at' => now(),
            'inactive_reason' => $reason,
            // Zdjęta oferta nie jest już okazją.
            'deal_reasons' => null,
            'deal_at' => null,
        ])->save();

        // Powiadomienie tylko o mieszkaniu, które całe zniknęło (nie ma go też na innym portalu).
        $main = $listing->duplicate_of_id ? $listing->originalListing : $listing;

        if ($main !== null && in_array($main->status, self::WATCHED, true) && $main->isGone()) {
            $this->panel->listingGone($main);
        }
    }

    /**
     * @param  array{status: int, body: string, url: string}  $page
     */
    public static function classify(Listing $listing, array $page): ?string
    {
        if (in_array($page['status'], [404, 410], true)) {
            return "ogłoszenie usunięte z portalu (HTTP {$page['status']})";
        }

        if ($page['status'] >= 400) {
            return null;
        }

        if (self::redirectedAway($listing->url, $page['url'])) {
            return 'portal przekierowuje na listę ofert';
        }

        $marker = config("scrapers.availability.markers.{$listing->portal}");

        if ($marker !== null && preg_match($marker, $page['body']) === 1) {
            return 'portal oznacza ogłoszenie jako archiwalne';
        }

        return null;
    }

    /**
     * Przekierowanie "w górę": na stronę główną albo listę ofert (krótsza ścieżka).
     * Zmiana samego sluga (ta sama głębokość, np. Otodom) nie liczy się.
     */
    private static function redirectedAway(string $from, string $to): bool
    {
        $fromPath = trim((string) parse_url($from, PHP_URL_PATH), '/');
        $toPath = trim((string) parse_url($to, PHP_URL_PATH), '/');

        if ($fromPath === '' || rawurldecode($fromPath) === rawurldecode($toPath)) {
            return false;
        }

        return $toPath === '' || substr_count($toPath, '/') < substr_count($fromPath, '/');
    }
}
