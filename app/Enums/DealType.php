<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DealType: string implements HasColor, HasLabel
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

    public function getColor(): string
    {
        return match ($this) {
            self::Rent => 'info',
            self::Sale => 'success',
        };
    }
}
