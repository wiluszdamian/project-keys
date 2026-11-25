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
 * Krótkie podsumowanie tygodniowego raportu rynku dla osoby, która szuka mieszkania.
 */
#[Temperature(0.3)]
#[Timeout(120)]
class MarketReportAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        Piszesz krótkie podsumowanie tygodniowego raportu rynku mieszkań (wynajem albo sprzedaż) dla osoby, która szuka mieszkania.
        Dostajesz liczby z raportu, trend z kilku tygodni, preferencje osoby i jej listę obserwowanych ofert.

        Zwróć:
        - summary: 3-4 zdania. Co się zmieniło w tym tygodniu i co z tego wynika dla tej osoby (np. lepszy moment na negocjacje, mniej ofert, drożej w jednej lokalizacji).
        - points: 2-4 konkretne punkty do zrobienia albo sprawdzenia w tym tygodniu, oparte na danych (np. "zadzwoń w sprawie X, cena spadła o 5%", "oglądanie w środę").

        Zasady:
        - Tylko fakty z danych, z liczbami. Nie wymyślaj ofert ani wydarzeń.
        - Gdy porównania z poprzednim tygodniem nie ma albo trend ma 1-2 tygodnie, napisz to krótko i nie wyciągaj daleko idących wniosków.
        - Prosty język, po polsku, bez myślników jako znaków interpunkcyjnych, bez ozdobników.

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
            'summary' => $schema->string()->required(),
            'points' => $schema->array()->items($schema->string())->max(4)->required(),
        ];
    }
}
