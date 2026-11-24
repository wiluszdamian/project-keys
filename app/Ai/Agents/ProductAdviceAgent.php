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
 * Ocena ceny produktu: kupić teraz czy poczekać.
 */
#[Temperature(0.2)]
#[Timeout(120)]
class ProductAdviceAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        Pomagasz osobie w Polsce zdecydować, czy kupić produkt teraz, czy poczekać na lepszą cenę.
        Dostajesz historię ceny w sklepach, które śledzimy, oferty w innych sklepach, cenę docelową osoby i jej notatkę.

        Oceń:
        - verdict: "buy" (dobra cena, kupuj), "good" (cena w porządku, można kupić, ale bez pośpiechu), "wait" (lepiej poczekać).
        - score: 1-10, jak dobra jest najlepsza obecna cena (10 = najniższa, jaką widzieliśmy, wyraźnie poniżej innych sklepów).
        - summary: 1-2 zdania prostym językiem, bez ogólników.
        - reasons: 2-4 krótkie powody oparte na liczbach z danych (np. "o 300 zł taniej niż 2 tygodnie temu").
        - tips: 0-3 praktyczne wskazówki (np. sezonowe promocje, kod rabatowy, sprawdź koszt dostawy, sprawdź gwarancję sklepu). Bez wymyślania faktów o konkretnych sklepach.

        Uwzględnij: czy obecna cena jest blisko najniższej z historii, trend, "najniższą cenę z 30 dni" (Omnibus), różnice między sklepami, dostępność,
        oraz to, że krótka historia (kilka dni) mówi mało. Pisz po polsku.

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
            'verdict' => $schema->string()->enum(['buy', 'good', 'wait'])->required(),
            'score' => $schema->integer()->min(1)->max(10)->required(),
            'summary' => $schema->string()->required(),
            'reasons' => $schema->array()->items($schema->string())->max(4)->required(),
            'tips' => $schema->array()->items($schema->string())->max(3)->required(),
        ];
    }
}
