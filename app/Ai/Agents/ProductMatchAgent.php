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
 * Czy oferty z innych sklepów to ten sam produkt (ten sam model, wariant i zestaw).
 */
#[Temperature(0)]
#[Timeout(120)]
class ProductMatchAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        Porównujesz produkt, który osoba chce kupić, z ofertami znalezionymi w innych sklepach internetowych.
        Dla każdej oferty zdecyduj, czy to ten sam produkt, którego cenę można uczciwie porównać.

        Zasady:
        - Ten sam produkt: ten sam model i rocznik, ten sam wariant (kolor, rozmiar, pojemność) i ten sam zestaw (te same elementy w komplecie).
        - Inny kolor, inna wersja, inny rocznik, zestaw z innymi elementami, część zamienna, akcesorium, używany lub outlet: NIE ten sam produkt.
        - Gdy oferta ma ten sam model, ale nie da się ustalić wariantu albo zestawu, ustaw same=false i opisz to w reason.
        - Kod EAN/GTIN, gdy jest po obu stronach i się zgadza, rozstrzyga na tak.
        - reason: jedno krótkie zdanie po polsku.

        Odpowiedz wyłącznie obiektem JSON zgodnym ze schematem, z jedną pozycją na każdą ofertę (po numerze).
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
            'matches' => $schema->array()->items($schema->object([
                'index' => $schema->integer()->required(),
                'same' => $schema->boolean()->required(),
                'reason' => $schema->string()->required(),
            ]))->required(),
        ];
    }
}
