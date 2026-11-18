<?php

namespace App\Notifications;

use App\Enums\DealType;
use App\Enums\NotifyMode;
use App\Support\NotifySchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Zbiorcze powiadomienia dla kanału: wszystkie wyszukiwania na wynajem (albo sprzedaż) w jednej wiadomości
 * co N godzin albo raz dziennie. Ustawienie kanału wygrywa z ustawieniami wyszukiwań.
 */
class NotifyGroups
{
    public const PER_SEARCH = 'per_search';

    /**
     * Tryb kanału albo null, gdy decydują wyszukiwania.
     */
    public static function mode(DealType $dealType): ?NotifyMode
    {
        $schedule = config("notify.groups.{$dealType->value}.schedule");

        return $schedule === self::PER_SEARCH ? null : NotifyMode::tryFrom((string) $schedule);
    }

    public static function isCombined(DealType $dealType): bool
    {
        $mode = self::mode($dealType);

        return $mode !== null && $mode !== NotifyMode::Instant;
    }

    public static function due(DealType $dealType, Carbon $now): bool
    {
        $last = Cache::get(self::key($dealType, 'last_sent'));

        return NotifySchedule::due(
            self::mode($dealType),
            (int) config("notify.groups.{$dealType->value}.every_hours"),
            config("notify.groups.{$dealType->value}.daily_at"),
            $last ? Carbon::parse($last) : null,
            $now,
        );
    }

    public static function markSent(DealType $dealType, Carbon $now): void
    {
        Cache::forever(self::key($dealType, 'last_sent'), $now->toIso8601String());
    }

    public static function periodLabel(DealType $dealType): string
    {
        return NotifySchedule::periodLabel(self::mode($dealType), (int) config("notify.groups.{$dealType->value}.every_hours"));
    }

    /**
     * Obniżka ceny czeka na podsumowanie kanału. Zostaje pierwsza stara cena i ostatnia nowa.
     */
    public static function queuePriceDrop(DealType $dealType, int $listingId, int $searchId, int $oldPrice, int $newPrice): void
    {
        $key = self::key($dealType, 'price_drops');
        $drops = Cache::get($key, []);
        $drops[$listingId] = [
            'listing_id' => $listingId,
            'search_id' => $searchId,
            'old_price' => $drops[$listingId]['old_price'] ?? $oldPrice,
            'new_price' => $newPrice,
        ];
        Cache::forever($key, $drops);
    }

    /**
     * @return array<int, array{listing_id: int, search_id: int, old_price: int, new_price: int}>
     */
    public static function pullPriceDrops(DealType $dealType): array
    {
        return Cache::pull(self::key($dealType, 'price_drops'), []);
    }

    private static function key(DealType $dealType, string $name): string
    {
        return "notify:group:{$dealType->value}:{$name}";
    }
}
