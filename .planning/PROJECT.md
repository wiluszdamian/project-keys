# Projekt

## Cel

Prywatne narzędzie dla jednej osoby (właściciela). Zastępuje ręczne odświeżanie portali z ogłoszeniami mieszkań.

1. Użytkownik zapisuje wyszukiwania: miasto, dzielnice, wynajem lub sprzedaż, cena, metraż, liczba pokoi, portale, własne preferencje tekstem (np. „balkon, blisko metra, bez agencji”).
2. Co 15 do 30 minut system sprawdza portale według tych filtrów.
3. Nowe oferty zapisuje w bazie, pomija duplikaty.
4. Każdą nową ofertę ocenia model AI (ocena 1 do 10, podsumowanie, plusy, ryzyka).
5. Oferty z oceną powyżej progu wysyła na Telegram lub Discord.

## Zakres pierwszej wersji

- Portale: Otodom i OLX. Morizon i Gratka w fazie opcjonalnej.
- Typy: mieszkania na wynajem i na sprzedaż.
- Zarządzanie wyszukiwaniami przez API (token) i komendy artisan.
- Powiadomienia: Telegram, Discord.
- Ocena AI: Laravel AI (wbudowany moduł providerów) z OpenCode Go i modelem DeepSeek.

## Poza zakresem

- Panel www dla wielu użytkowników, rejestracja, płatności.
- Pobieranie i przechowywanie zdjęć (zapisujemy tylko linki).
- Automatyczne pisanie do ogłoszeniodawców.
- Omijanie zabezpieczeń portali (CAPTCHA, logowanie, rotacja proxy).

## Założenia

- Serwer: VPS Contabo, Ubuntu 24.04, x86_64. Dokładne parametry wpisać w STATUS.md po fazie 0.
- Wszystko działa w Docker Compose.
- Użytkownik ma domenę lub subdomenę wskazującą na VPS (potrzebne do HTTPS). Jeśli nie ma, API działa tylko przez tunel SSH (patrz DEPLOYMENT.md).

## Uwaga o regulaminach

Regulaminy portali ograniczają automatyczne pobieranie danych. Narzędzie jest do prywatnego użytku i ma działać oszczędnie: mało zapytań, długie przerwy, natychmiastowe wycofanie się przy blokadzie. Szczegóły w SCRAPERS.md.
