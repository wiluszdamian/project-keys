<?php

namespace App\Models;

use App\Enums\DealType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class MarketReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'deal_type' => DealType::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'data' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Np. "14–20 lis 2026". Koniec okresu to sobota 20:00, więc dzień końca to ten sam dzień.
     */
    public function periodLabel(): string
    {
        return self::label($this->period_start, $this->period_end);
    }

    public static function label(CarbonInterface $start, CarbonInterface $end): string
    {
        $start = $start->copy()->locale('pl');
        $end = $end->copy()->locale('pl');

        return $start->isSameMonth($end)
            ? $start->format('j').'–'.$end->translatedFormat('j M Y')
            : $start->translatedFormat('j M').' – '.$end->translatedFormat('j M Y');
    }
}
