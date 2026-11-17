# Ocena ofert przez AI

## Technologia

Sprawdzone 2026-11-17 (faza 6):

- Laravel AI SDK: paczka `laravel/ai` (v1.4.1), konfiguracja `config/ai.php`, provider `opencode` ze sterownikiem `openai-compatible`, odpowiedź JSON przez `HasStructuredOutput`. Agent: `app/Ai/Agents/ListingScoringAgent.php`.
- OpenCode Go: API zgodne z OpenAI pod `https://opencode.ai/inference/go/openai/v1`, `Authorization: Bearer`, własny User-Agent i nagłówek `x-opencode-session`. Model `deepseek-v4.1-flash`. DeepSeek wymaga regionu Global w ustawieniach prywatności workspace (włączone przez właściciela).
- Warunki: Go jest przeznaczony dla agentów do kodowania (otwarte pytanie w STATUS.md). Pay-as-you-go: `OPENCODE_GO_BASE_URL=https://opencode.ai/inference/openai/v1`, bez zmian w kodzie.

Konfiguracja tylko przez `.env`: `OPENCODE_GO_BASE_URL`, `OPENCODE_GO_API_KEY`, `OPENCODE_GO_MODEL`, `AI_PROVIDER`, `AI_ENABLED`, `AI_MAX_PER_MINUTE`.

## Kiedy oceniamy

- Tylko nowe dopasowania (search_listing bez analizy) albo ogłoszenia ze zmienionym `content_hash`.
- Job `AnalyzeListingJob` w kolejce `ai`.
- Limit: maksymalnie `AI_MAX_PER_MINUTE` (domyślnie 20) wywołań na minutę (rate limiter w Redis).
- Timeout 60 s, 3 próby z rosnącą przerwą (10 s, 60 s, 300 s).
- Opis ogłoszenia przycinamy do 3000 znaków.
- Przy pierwszym przebiegu wyszukiwania (dużo ofert naraz) też oceniamy, ale powiadomienie idzie jako jedno podsumowanie.

## Dane wejściowe dla modelu

- Preferencje użytkownika: `searches.ai_preferences` oraz filtry wyszukiwania.
- Ogłoszenie: tytuł, cena, czynsz dodatkowy, metraż, cena za m2, pokoje, piętro, dzielnica, ulica, prywatne czy agencja, opis.
- Kontekst rynkowy z naszej bazy (`MarketStats`): mediana ceny za m2 dla tego miasta i dzielnicy oraz typu transakcji z ostatnich 60 dni, i liczba ofert, z których policzono medianę. Jeśli ofert jest mniej niż 10, przekaż informację, że danych jest mało. Model nie ma oceniać cen „z głowy”.

## Prompt systemowy (wersja startowa)

```
Jesteś asystentem, który ocenia ogłoszenia mieszkań w Polsce dla jednej osoby.
Oceniasz, jak dobrze oferta pasuje do jej preferencji i czy jest warta szybkiego kontaktu.

Zasady:
- Opieraj się tylko na danych z ogłoszenia i podanym kontekście rynkowym. Nie zgaduj brakujących informacji.
- Porównuj cenę za m2 z podaną medianą. Jeśli danych rynkowych jest mało, napisz to i nie oceniaj ceny surowo.
- Zwracaj uwagę na ryzyka: cena podejrzanie niska (możliwe oszustwo), brak adresu, prośba o przedpłatę przed obejrzeniem, ukryte koszty, sprzeczności w opisie, „bez zwierząt” lub inne ograniczenia sprzeczne z preferencjami.
- Pisz krótko, prostym językiem, po polsku.

Odpowiedz wyłącznie obiektem JSON zgodnym ze schematem.
```

Aktualny prompt jest w `ListingScoringAgent::instructions()`. Doszły zasady o koszcie miesięcznym, ocenach cząstkowych i o tym, że nazwa osiedla w tytule lub opisie liczy się bardziej niż dzielnica z portalu.

## Schemat odpowiedzi

Rozszerzony na prośbę właściciela o koszt miesięczny i oceny cząstkowe:

```json
{
  "score": 7,
  "summary": "Jedno lub dwa zdania.",
  "monthly_cost": {"rent": 2500, "admin_fee": 450, "other_fees": 200, "total": 3150, "note": "Media ok. 200 zł, kaucja 5000 zł."},
  "sub_scores": {"cost": 6, "location": 9, "standard": 7, "fit": 8},
  "pros": ["max 4 krótkie punkty"],
  "red_flags": ["max 4 krótkie punkty, może być pusta lista"]
}
```

Walidacja w `ListingScorer`: score to liczba całkowita 1 do 10, summary niepuste, max 4 elementy w listach. Gdy odpowiedź jest niepoprawna: jedna ponowna próba, potem zapisz błąd i wyślij ofertę bez oceny (z dopiskiem „brak oceny AI”), jeśli spełnia filtry.

## Koszty

Zapisuj `input_tokens` i `output_tokens`. Komenda `php artisan ai:usage` pokazuje sumę z dzisiaj i z ostatnich 30 dni.
