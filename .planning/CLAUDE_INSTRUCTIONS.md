# Zasady pracy dla Claude Code

## Sposób pracy

- Pracuj fazami z `ROADMAP.md`, po kolei. Nie zaczynaj kolejnej fazy bez zgody użytkownika.
- Po każdej fazie: uruchom testy, zrób commit po angielsku z prefiksem (`feat:`, `fix:`, `chore:`, `docs:`, `test:`, `refactor:`), kilka słów, bez linii Co-Authored-By, zaktualizuj `.planning/STATUS.md`, pokaż krótkie podsumowanie i zatrzymaj się.
- Jeśli coś w planie nie zgadza się z rzeczywistością (np. inna wersja biblioteki, portal działa inaczej), wybierz najprostsze rozwiązanie, zapisz decyzję w `STATUS.md` w sekcji „Decyzje” i powiedz o tym użytkownikowi.

## Nie zgaduj

Miejsca oznaczone **SPRAWDŹ** wymagają weryfikacji w aktualnej dokumentacji albo na żywej stronie. Dotyczy to zwłaszcza:

- wbudowanego modułu AI w najnowszym Laravelu (nazwa paczki, konfiguracja providerów, structured output),
- OpenCode Go (adres API, format zgodności, dokładne ID modelu DeepSeek),
- struktury danych na portalach (Otodom, OLX i inne).

Nie wymyślaj nazw metod, adresów URL ani ID modeli. Jeśli nie da się sprawdzić, zapytaj użytkownika.

## Bezpieczeństwo

- Sekrety tylko w `.env`. Plik `.env` musi być w `.gitignore`. Nigdy nie wypisuj sekretów w logach ani w odpowiedziach.
- Przed komendami, które zmieniają system lub kasują dane, zapytaj użytkownika. Przykłady: zmiany w `sshd_config`, `ufw`, `docker volume rm`, `migrate:fresh` na produkcji, usuwanie plików poza katalogiem projektu.
- Uważaj przy zmianach SSH i firewalla, żeby nie odciąć dostępu do serwera. Zawsze najpierw zezwól na port SSH, potem włączaj firewall.

## Kod

- Jedna aplikacja Laravel. Bez mikroserwisów, bez osobnego frontendu.
- Nazwy klas, tabel, kolumn i zmiennych po angielsku. Komentarze, README i treść powiadomień po polsku.
- Testy w Pest. Scrapery testuj na zapisanych plikach (fixtures) w `tests/Fixtures`, bez prawdziwych zapytań do sieci. Zapytania do AI w testach mockuj.
- Prosty, czytelny kod. Bez zbędnych warstw abstrakcji.
- Wszystkie długie operacje (scraping, AI, wysyłka) jako joby w kolejce.
