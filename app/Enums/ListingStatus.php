<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Status nadany ręcznie w panelu. Odrzucone oferty nie dają już powiadomień.
 */
enum ListingStatus: string implements HasColor, HasLabel
{
    case Favorite = 'favorite';
    case Contacted = 'contacted';
    case Viewing = 'viewing';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Favorite => 'Ulubiona',
            self::Contacted => 'Kontakt',
            self::Viewing => 'Oglądanie',
            self::Rejected => 'Odrzucona',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Favorite => 'warning',
            self::Contacted => 'info',
            self::Viewing => 'success',
            self::Rejected => 'gray',
        };
    }
}
