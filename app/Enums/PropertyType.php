<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PropertyType: string implements HasLabel
{
    case Flat = 'flat';
    case House = 'house';

    public function getLabel(): string
    {
        return match ($this) {
            self::Flat => 'Mieszkanie',
            self::House => 'Dom',
        };
    }
}
