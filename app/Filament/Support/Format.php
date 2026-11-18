<?php

namespace App\Filament\Support;

use Illuminate\Support\Number;

class Format
{
    public static function pln(int|float|string|null $value): ?string
    {
        return $value === null || $value === '' ? null : Number::format((float) $value, locale: 'pl').' zł';
    }

    public static function scoreColor(?int $score): string
    {
        return match (true) {
            $score === null => 'gray',
            $score >= 8 => 'success',
            $score >= 6 => 'warning',
            default => 'danger',
        };
    }
}
