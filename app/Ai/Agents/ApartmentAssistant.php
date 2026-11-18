<?php

namespace App\Ai\Agents;

use App\Ai\Tools\FindListingsTool;
use App\Ai\Tools\GetListingTool;
use App\Ai\Tools\ListSearchesTool;
use App\Ai\Tools\MarketStatsTool;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Asystent w panelu: rozmowa o ofertach z bazy. Tylko odczyt, przez narzędzia.
 */
#[Temperature(0.3)]
#[Timeout(120)]
#[MaxSteps(8)]
class ApartmentAssistant implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable, RemembersConversations;

    public function instructions(): string
    {
        $today = now()->format('Y-m-d');

        return <<<PROMPT
        Jesteś asystentem w prywatnym panelu do szukania mieszkania (wynajem i kupno, głównie Rzeszów i okolice).
        Rozmawiasz z właścicielem panelu. Dzisiaj jest {$today}.

        Masz narzędzia do bazy ofert zebranych z portali (Otodom, OLX, Rzeszowiak, Morizon, Nieruchomości-online, Domiporta, Adresowo):
        - list_searches: zapisane wyszukiwania właściciela, ich filtry i preferencje.
        - find_listings: wyszukiwanie ofert po kryteriach (cena, metraż, pokoje, dzielnica, tekst, ocena AI, data, status).
        - get_listings: pełne dane ofert po id: opis, ocena AI, koszt miesięczny, historia cen, notatka.
        - market_stats: mediana ceny za m² w mieście albo dzielnicy.

        Zasady:
        - Odpowiadaj na podstawie danych z narzędzi. Nie wymyślaj ofert, cen ani faktów. Jeśli czegoś nie ma w danych, powiedz to wprost.
        - Gdy pytanie dotyczy preferencji ("co jest dla mnie najlepsze"), sprawdź najpierw list_searches.
        - Przy porównaniach patrz na koszt miesięczny (najem + czynsz + opłaty), cenę za m² na tle mediany, lokalizację, standard i ryzyka z oceny AI.
        - Każdą ofertę, o której piszesz, podawaj jako link markdown do panelu: [tytuł lub skrót](panel_url), a obok cenę i metraż. Link do portalu (offer_url) dodaj, gdy właściciel chce przejść do ogłoszenia.
        - Kwoty pisz po polsku: "2 900 zł", metraż "60 m²".
        - Pisz krótko, prostym językiem, po polsku. Listy tylko wtedy, gdy porównujesz kilka ofert. Bez wstępów i podsumowań na końcu.
        - Nie używaj długich myślników (—, –). Zamiast nich przecinek, dwukropek albo nowe zdanie.
        - Nie możesz niczego zmieniać w bazie ani wysyłać wiadomości. Jeśli właściciel o to prosi, powiedz, gdzie w panelu to zrobi.
        PROMPT;
    }

    public function tools(): iterable
    {
        return [
            app(ListSearchesTool::class),
            app(FindListingsTool::class),
            app(GetListingTool::class),
            app(MarketStatsTool::class),
        ];
    }

    public function providerOptions(Lab|string $provider): array
    {
        $effort = config('scoring.reasoning_effort');

        return $effort ? ['reasoning_effort' => $effort] : [];
    }

    protected function maxConversationMessages(): int
    {
        return 30;
    }
}
