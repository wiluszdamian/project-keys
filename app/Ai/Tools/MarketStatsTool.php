<?php

namespace App\Ai\Tools;

use App\Enums\DealType;
use App\Services\MarketStats;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class MarketStatsTool implements Tool
{
    public function __construct(private MarketStats $stats) {}

    public function name(): string
    {
        return 'market_stats';
    }

    public function description(): Stringable|string
    {
        return 'Mediana ceny za m² z naszej bazy (ostatnie 60 dni) dla miasta albo dzielnicy, osobno dla wynajmu i sprzedaży. Przy wynajmie także mediana (najem + czynsz) za m².';
    }

    public function handle(Request $request): Stringable|string
    {
        $deal = DealType::tryFrom((string) ($request['deal_type'] ?? '')) ?? DealType::Rent;

        return json_encode($this->stats->forArea((string) ($request['city'] ?? 'Rzeszów'), $deal, $request['district'] ?? null), JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()->required(),
            'deal_type' => $schema->string()->enum(['rent', 'sale'])->required(),
            'district' => $schema->string(),
        ];
    }
}
