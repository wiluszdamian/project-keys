<?php

namespace App\Notifications\Discord;

use App\Enums\DealType;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Wysyłka na webhook Discorda. Przerwa 1,5 s między wiadomościami i obsługa 429.
 */
class DiscordWebhook
{
    private const ATTEMPTS = 4;

    public function isConfigured(DealType|string|null $channel = null): bool
    {
        return $this->urlFor($channel) !== null;
    }

    /**
     * Osobny webhook dla wynajmu, sprzedaży albo monitora cen ('products'), a gdy go nie ma, główny.
     */
    public function urlFor(DealType|string|null $channel = null): ?string
    {
        $specific = match ($channel) {
            DealType::Rent => config('notify.discord.webhook_url_rent'),
            DealType::Sale => config('notify.discord.webhook_url_sale'),
            'products' => config('notify.discord.webhook_url_products'),
            default => null,
        };

        return ($specific ?: config('notify.discord.webhook_url')) ?: null;
    }

    public function send(array $payload, DealType|string|null $channel = null): void
    {
        $url = $this->urlFor($channel) ?? throw new RuntimeException('Brak DISCORD_WEBHOOK_URL w .env.');
        $key = 'discord:'.sha1($url);
        $payload = ['username' => config('notify.discord.username')] + $payload;

        Cache::lock("{$key}:lock", 60)->block(120, function () use ($url, $key, $payload) {
            $this->waitForTurn($key);

            try {
                $this->post($url, $payload);
            } finally {
                Cache::put("{$key}:last", microtime(true), now()->addMinute());
            }
        });
    }

    private function post(string $url, array $payload): void
    {
        for ($attempt = 1; ; $attempt++) {
            $response = Http::timeout(15)->post($url, $payload);

            if ($response->status() === 429 && $attempt < self::ATTEMPTS) {
                // Discord podaje czas oczekiwania w sekundach (retry_after).
                $seconds = (float) ($response->json('retry_after') ?? $response->header('Retry-After') ?: 1);
                Sleep::for((int) ceil($seconds * 1000) + 100)->milliseconds();

                continue;
            }

            if ($response->serverError() && $attempt < self::ATTEMPTS) {
                Sleep::for(5)->seconds();

                continue;
            }

            try {
                $response->throw();
            } catch (RequestException $e) {
                // Bez adresu webhooka w treści błędu, bo to sekret.
                throw new RuntimeException("Discord odpowiedział {$response->status()}: ".mb_substr($response->body(), 0, 300));
            }

            return;
        }
    }

    private function waitForTurn(string $key): void
    {
        $last = Cache::get("{$key}:last");

        if ($last === null) {
            return;
        }

        $elapsedMs = (int) ((microtime(true) - $last) * 1000);
        $delayMs = config('notify.discord.delay_ms');

        if ($elapsedMs < $delayMs) {
            Sleep::for($delayMs - $elapsedMs)->milliseconds();
        }
    }
}
