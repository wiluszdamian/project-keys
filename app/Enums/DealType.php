<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DealType: string implements HasLabel
{
    case Rent = 'rent';
    case Sale = 'sale';

    public function getLabel(): string
    {
        return match ($this) {
            self::Rent => 'Wynajem',
            self::Sale => 'Sprzedaż',
        };
    }
}
