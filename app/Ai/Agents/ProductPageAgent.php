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
 * Odczyt ceny z treści strony sklepu, gdy sklep nie podaje jej w danych strony.
 */
#[Temperature(0)]
#[Timeout(90)]
class ProductPageAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        Dostajesz tekst strony produktu ze sklepu internetowego (bez HTML). Odczytaj dane głównego produktu tej strony.

        Zasady:
        - price: aktualna cena, za którą można kupić produkt teraz (po promocji, bez kosztu dostawy). Liczba, kropka jako separator dziesiętny.
        - regular_price: cena przed promocją (przekreślona), gdy jest. Inaczej null.
        - lowest_30d_price: "najniższa cena z 30 dni", gdy strona ją podaje. Inaczej null.
        - Pomiń ceny innych produktów (polecane, akcesoria, "klienci kupili też"), raty, dopłaty i koszty dostawy.
        - available: true gdy można kupić lub zamówić, false gdy niedostępny lub wyprzedany, null gdy nie wiadomo.
        - found: false, gdy na stronie nie ma ceny głównego produktu albo to nie jest strona produktu.
        - Nie zgaduj. Lepiej found=false niż zła cena.

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
            'found' => $schema->boolean()->required(),
            'name' => $schema->string()->nullable()->required(),
            'price' => $schema->number()->nullable()->required(),
            'regular_price' => $schema->number()->nullable()->required(),
            'lowest_30d_price' => $schema->number()->nullable()->required(),
            'currency' => $schema->string()->nullable()->required(),
            'available' => $schema->boolean()->nullable()->required(),
        ];
    }
}
