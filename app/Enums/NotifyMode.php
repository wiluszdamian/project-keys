<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Jak często wyszukiwanie wysyła nowe oferty na Discorda.
 */
enum NotifyMode: string implements HasLabel
{
    case Instant = 'instant';
    case EveryHours = 'every_hours';
    case Daily = 'daily';

    public function getLabel(): string
    {
        return match ($this) {
            self::Instant => 'Od razu, po każdym przebiegu',
            self::EveryHours => 'Zbiorczo co kilka godzin',
            self::Daily => 'Raz dziennie o wybranej godzinie',
        };
    }
}
