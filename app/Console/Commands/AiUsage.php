<?php

namespace App\Console\Commands;

use App\Models\ListingAnalysis;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:usage')]
#[Description('Zużycie tokenów AI: dzisiaj i z ostatnich 30 dni')]
class AiUsage extends Command
{
    public function handle(): int
    {
        $rows = collect([
            'Dzisiaj' => now()->startOfDay(),
            'Ostatnie 30 dni' => now()->subDays(30),
        ])->map(fn ($from, $label) => [
            $label,
            ListingAnalysis::where('updated_at', '>=', $from)->count(),
            number_format((int) ListingAnalysis::where('updated_at', '>=', $from)->sum('input_tokens'), 0, ',', ' '),
            number_format((int) ListingAnalysis::where('updated_at', '>=', $from)->sum('output_tokens'), 0, ',', ' '),
        ])->values();

        $this->table(['Okres', 'Oceny', 'Tokeny wejście', 'Tokeny wyjście'], $rows);
        $this->line('Model: '.(config('ai.providers.'.config('ai.default').'.models.text.default') ?? '?'));

        return self::SUCCESS;
    }
}
