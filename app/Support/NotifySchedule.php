<?php

namespace App\Support;

use App\Enums\NotifyMode;
use Illuminate\Support\Carbon;

/**
 * Kiedy wysłać zebrane oferty: co N godzin albo raz dziennie o godzinie. Wspólne dla wyszukiwań i kanałów.
 */
class NotifySchedule
{
    public static function due(NotifyMode $mode, ?int $everyHours, ?string $dailyAt, ?Carbon $lastSentAt, Carbon $now): bool
    {
        return match ($mode) {
            NotifyMode::Instant => true,
            NotifyMode::EveryHours => $lastSentAt === null
                || $lastSentAt->lte($now->copy()->subHours(max(1, (int) $everyHours))),
            NotifyMode::Daily => self::dailyTime($dailyAt, $now)->lte($now)
                && ($lastSentAt === null || $lastSentAt->lt(self::dailyTime($dailyAt, $now))),
        };
    }

    public static function periodLabel(NotifyMode $mode, ?int $everyHours): string
    {
        return match ($mode) {
            NotifyMode::EveryHours => 'Podsumowanie z ostatnich '.max(1, (int) $everyHours).' h',
            NotifyMode::Daily => 'Podsumowanie dnia',
            NotifyMode::Instant => 'Zaległe oferty',
        };
    }

    private static function dailyTime(?string $dailyAt, Carbon $now): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $dailyAt ?: '20:00') + [1 => 0]);

        return $now->copy()->setTime($hour, $minute);
    }
}
