<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Ocena jednego ogłoszenia dla jednego wyszukiwania. Provider i model z config/ai.php (.env).
 */
#[Temperature(0.2)]
#[Timeout(60)]
class ListingScoringAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        Jesteś asystentem, który ocenia ogłoszenia mieszkań w Polsce dla jednej osoby.
        Oceniasz, jak dobrze oferta pasuje do jej preferencji i czy jest warta szybkiego kontaktu.

        Zasady:
        - Opieraj się tylko na danych z ogłoszenia i podanym kontekście rynkowym. Nie zgaduj brakujących informacji.
        - Koszt miesięczny przy wynajmie to najem + czynsz administracyjny + inne stałe opłaty z opisu (media, internet, garaż, opłata za osobę). Szukaj ich w opisie. Jeśli opis mówi, że czynsz lub media są w cenie, nie dodawaj ich drugi raz. Jeśli czegoś nie wiadomo, wpisz null i napisz to w uwadze do kosztu. Kaucja nie jest kosztem miesięcznym, ale wspomnij o niej w uwadze, jeśli jest wysoka.
        - Przy sprzedaży koszt miesięczny to sam czynsz administracyjny, a rent to null.
        - Porównuj koszt z podaną medianą za m2. Jeśli danych rynkowych jest mało, napisz to i nie oceniaj ceny surowo.
        - Oceny cząstkowe od 1 do 10: cost (koszt na tle rynku i budżetu), location (lokalizacja wobec preferencji), standard (stan, wyposażenie, budynek), fit (ogólne dopasowanie do preferencji i filtrów). Ocena główna score to Twoja całościowa ocena, nie średnia.
        - Zwracaj uwagę na ryzyka: cena podejrzanie niska (możliwe oszustwo), brak adresu, prośba o przedpłatę przed obejrzeniem, ukryte koszty, sprzeczności w opisie, "bez zwierząt" lub inne ograniczenia sprzeczne z preferencjami, pokoje na wynajem zamiast całego mieszkania.
        - Pisz krótko, prostym językiem, po polsku. Najwyżej 4 punkty w pros i red_flags.

        Odpowiedz wyłącznie obiektem JSON zgodnym ze schematem.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->integer()->min(1)->max(10)->required(),
            'summary' => $schema->string()->description('Jedno lub dwa zdania.')->required(),
            'monthly_cost' => $schema->object([
                'rent' => $schema->integer()->nullable()->description('Najem w zł, null przy sprzedaży.'),
                'admin_fee' => $schema->integer()->nullable()->description('Czynsz administracyjny w zł, null gdy nieznany.'),
                'other_fees' => $schema->integer()->nullable()->description('Inne stałe opłaty miesięczne z opisu w zł, null gdy brak.'),
                'total' => $schema->integer()->nullable()->description('Suma miesięczna w zł.'),
                'note' => $schema->string()->description('Krótko: co jest w cenie, czego brakuje, kaucja.'),
            ])->required(),
            'sub_scores' => $schema->object([
                'cost' => $schema->integer()->min(1)->max(10)->required(),
                'location' => $schema->integer()->min(1)->max(10)->required(),
                'standard' => $schema->integer()->min(1)->max(10)->required(),
                'fit' => $schema->integer()->min(1)->max(10)->required(),
            ])->required(),
            'pros' => $schema->array()->items($schema->string())->max(4)->required(),
            'red_flags' => $schema->array()->items($schema->string())->max(4)->required(),
        ];
    }
}
