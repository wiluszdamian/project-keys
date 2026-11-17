# .planning: serwis do wyszukiwania mieszkań

Plan budowy prywatnego serwisu, który co kilkanaście minut sprawdza portale z ogłoszeniami (wynajem i sprzedaż) według zapisanych filtrów, ocenia nowe oferty przez AI i wysyła powiadomienia na Telegram lub Discord.

## Jak użyć

1. Na VPS utwórz pusty katalog projektu, np. `~/mieszkania`, i zrób w nim `git init`.
2. Skopiuj tam katalog `.planning`.
3. Uruchom Claude Code w katalogu projektu.
4. Pierwsza wiadomość do Claude Code:

   > Przeczytaj wszystkie pliki w .planning, zacznij od CLAUDE_INSTRUCTIONS.md. Potem wykonaj fazę 0 z ROADMAP.md i zatrzymaj się po niej.

5. Po każdej fazie sprawdź wynik i napisz „dalej”, żeby przejść do następnej.

## Pliki

| Plik | Co zawiera |
|---|---|
| CLAUDE_INSTRUCTIONS.md | Zasady pracy dla Claude Code. Czytać jako pierwszy. |
| PROJECT.md | Cel, zakres, czego nie robimy. |
| ARCHITECTURE.md | Usługi, przepływ danych, struktura kodu. |
| DATA_MODEL.md | Tabele i kolumny. |
| SCRAPERS.md | Jak pobierać ogłoszenia z portali, limity, duplikaty. |
| AI_SCORING.md | Ocena ofert przez Laravel AI + OpenCode Go + DeepSeek. |
| NOTIFICATIONS.md | Telegram i Discord. |
| API.md | Endpointy do zarządzania wyszukiwaniami. |
| DEPLOYMENT.md | Przygotowanie VPS Contabo, Docker, bezpieczeństwo, backup. |
| ROADMAP.md | Fazy pracy z kryteriami „gotowe”. |
| STATUS.md | Postęp i decyzje. Aktualizuje Claude Code. |
| env.example | Wzór pliku .env. |
