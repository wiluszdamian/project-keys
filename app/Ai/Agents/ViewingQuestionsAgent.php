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
 * Pytania na oglądanie mieszkania, dopasowane do ogłoszenia i preferencji osoby.
 */
#[Temperature(0.3)]
#[Timeout(120)]
class ViewingQuestionsAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
        Pomagasz osobie przygotować się do obejrzenia mieszkania w Polsce (wynajem albo zakup).
        Na podstawie ogłoszenia, oceny i preferencji zaproponuj pytania do właściciela lub agenta i rzeczy do sprawdzenia na miejscu.

        Zasady:
        - Tylko pytania konkretne dla tej oferty: luki i niejasności w opisie, sprzeczności, sygnały ryzyka, rzeczy ważne dla preferencji osoby.
        - Nie powtarzaj pytań z sekcji "Pytania, które już są na liście".
        - Nie pytaj o rzeczy, które ogłoszenie jasno podaje.
        - Każde pytanie krótkie, prostym językiem, po polsku, w formie, którą można zadać na głos. W "why" jedno krótkie zdanie, skąd to pytanie.
        - Najwyżej 8 pytań, od najważniejszego.

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
            'questions' => $schema->array()->items($schema->object([
                'question' => $schema->string()->required(),
                'why' => $schema->string()->required(),
            ]))->max(8)->required(),
        ];
    }
}
