<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Krótkie zapytanie do szukania produktu i polskie sklepy internetowe, które mogą go mieć.
 */
#[Temperature(0.2)]
#[Timeout(90)]
class ProductShopsAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        Pomagasz znaleźć ten sam produkt w innych polskich sklepach internetowych.

        Zwróć:
        - query: 2-5 słów, które na pewno są w nazwie tego produktu w każdym sklepie: marka i model (bez koloru, rocznika, opisu kategorii i dodatków).
          Przykład: dla "Cybex Balios S Lux 2026 Wózek Głęboko-Spacerowy ze Składaną Gondolą ... Chocolate Brown" query to "Cybex Balios S Lux".
        - shops: do 8 polskich sklepów internetowych (domena, np. "sklep.pl"), które zwykle sprzedają takie produkty i tę markę.
          Tylko sklepy, których jesteś pewien, że istnieją. Bez porównywarek (Ceneo, Skąpiec), marketplace'ów (Allegro, OLX, Amazon) i sklepu, który już jest podany.
          Najpierw sklepy specjalistyczne dla tej kategorii, potem duże sklepy ogólne. W "why" jedno krótkie zdanie.

        Odpowiedz wyłącznie obiektem JSON zgodnym ze schematem.
        PROMPT;
    }

    public function providerOptions(Lab|string $provider): array
    {
        $effort = config('scoring.reasoning_effort');

        return $effort ? ['reasoning_effort' => $effort] : [];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
            'shops' => $schema->array()->items($schema->object([
                'domain' => $schema->string()->required(),
                'name' => $schema->string()->required(),
                'why' => $schema->string()->required(),
            ]))->max(8)->required(),
        ];
    }
}
