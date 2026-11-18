<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ScrapeRunStatus: string implements HasColor, HasLabel
{
    case Running = 'running';
    case Ok = 'ok';
    case Blocked = 'blocked';
    case Error = 'error';

    public function getLabel(): string
    {
        return match ($this) {
            self::Running => 'W trakcie',
            self::Ok => 'OK',
            self::Blocked => 'Blokada',
            self::Error => 'Błąd',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Running => 'info',
            self::Ok => 'success',
            self::Blocked => 'warning',
            self::Error => 'danger',
        };
    }
}
