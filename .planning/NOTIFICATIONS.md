# Powiadomienia

Jeden system powiadomień Laravela. Kanał wybierany per wyszukiwanie (`searches.notify_channels`).

## Discord

- Webhook kanału (bez bota). URL w `.env`: `DISCORD_WEBHOOK_URL`.
- Wysyłka przez klienta HTTP Laravela, wiadomość jako embed.
- Opcjonalnie osobne webhooki dla wynajmu i sprzedaży: `DISCORD_WEBHOOK_URL_RENT`, `DISCORD_WEBHOOK_URL_SALE`. Gdy puste, używaj `DISCORD_WEBHOOK_URL`.

## Telegram

- Bot z @BotFather, token w `TELEGRAM_BOT_TOKEN`, odbiorca w `TELEGRAM_CHAT_ID`.
- Paczka `laravel-notification-channels/telegram` (**SPRAWDŹ** zgodność z aktualną wersją Laravela). Jeśli niezgodna, prosta wysyłka przez Bot API klientem HTTP.
- Przycisk pod wiadomością: „Otwórz ogłoszenie”.
- Komenda `php artisan telegram:chat-id` pomaga odczytać chat_id (czyta ostatnie wiadomości do bota przez getUpdates).

## Treść pojedynczej oferty

```
[ocena]/10  [tytuł]
[cena] zł (+ [czynsz] zł)  |  [metraż] m2  |  [cena za m2] zł/m2
[pokoje] pok.  |  piętro [piętro]  |  [dzielnica], [miasto]
[prywatne / agencja]  |  [portal]

[podsumowanie AI]
Plusy: ...
Uwaga: ... (tylko gdy są red_flags)

[link]
```

Przy zmianie ceny już wysłanej oferty: krótka wiadomość „Obniżka: [stara] -> [nowa] zł” (tylko obniżki, opcja w `.env`: `NOTIFY_PRICE_DROPS=true`).

## Podsumowanie (digest)

Wysyłane zamiast pojedynczych wiadomości, gdy:

- to pierwszy przebieg wyszukiwania (`first_run_completed = false`), albo
- w jednym przebiegu jest więcej nowych ofert niż `NOTIFY_DIGEST_THRESHOLD` (domyślnie 5).

Treść: liczba nowych ofert, 10 najlepszych według oceny (tytuł, cena, ocena, link). Pozostałe oznacz jako `notify_skipped_reason = digest`.

## Limity i niezawodność

- Kolejka `notify`, opóźnienie 1,5 s między wiadomościami do tego samego kanału.
- Obsługa odpowiedzi 429 (Discord i Telegram podają czas oczekiwania): ponów po wskazanym czasie.
- `notified_at` ustawiane dopiero po udanej wysyłce, żeby nie dublować i nie gubić.
- Komenda `php artisan notify:test {channel}` wysyła wiadomość testową.

## Alerty systemowe

Na ten sam kanał: blokada portalu 3 razy z rzędu, brak udanego przebiegu od 3 godzin, błędy AI w ponad połowie wywołań w ostatniej godzinie. Każdy alert maksymalnie raz na 6 godzin.
