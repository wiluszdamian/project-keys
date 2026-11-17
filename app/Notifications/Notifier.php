<?php

namespace App\Notifications;

use App\Models\Listing;
use App\Models\ListingAnalysis;
use App\Models\Search;
use App\Notifications\Discord\DiscordMessages;
use App\Notifications\Discord\DiscordWebhook;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Jedno miejsce, które wysyła wiadomości na kanały wybrane w wyszukiwaniu.
 * Na razie Discord. Telegram dojdzie w fazie 7.
 */
class Notifier
{
    public function __construct(
        private DiscordWebhook $discord,
        private DiscordMessages $messages,
    ) {}

    /**
     * Czy wyszukiwanie ma choć jeden skonfigurowany kanał.
     */
    public function canNotify(Search $search): bool
    {
        return in_array('discord', $search->notify_channels, true) && $this->discord->isConfigured($search->deal_type);
    }

    public function listing(Search $search, Listing $listing, ?ListingAnalysis $analysis = null): void
    {
        $this->toChannels($search, $this->messages->listing($listing, $search, $analysis));
    }

    public function digest(Search $search, Collection $listings, int $total, bool $firstRun, ?int $qualifying = null): void
    {
        $this->toChannels($search, $this->messages->digest($search, $listings, $total, $firstRun, $qualifying));
    }

    public function priceDrop(Search $search, Listing $listing, int $oldPrice, int $newPrice): void
    {
        $this->toChannels($search, $this->messages->priceDrop($listing, $search, $oldPrice, $newPrice));
    }

    /**
     * Alert systemowy na główny kanał. Ten sam klucz najwyżej raz na 6 godzin.
     */
    public function alert(string $key, string $title, string $body): bool
    {
        if (! $this->discord->isConfigured()) {
            Log::warning("Alert bez kanału: {$title}. {$body}");

            return false;
        }

        if (! Cache::add("alert:{$key}", true, now()->addHours(config('notify.alert_cooldown_hours')))) {
            return false;
        }

        $this->discord->send($this->messages->alert($title, $body));

        return true;
    }

    public function test(string $channel): void
    {
        match ($channel) {
            'discord' => $this->discord->send($this->messages->test()),
            default => throw new \InvalidArgumentException("Kanał {$channel} nie jest jeszcze obsługiwany."),
        };
    }

    private function toChannels(Search $search, array $discordPayload): void
    {
        if (in_array('discord', $search->notify_channels, true)) {
            $this->discord->send($discordPayload, $search->deal_type);
        }

        if (in_array('telegram', $search->notify_channels, true)) {
            Log::info("Telegram jeszcze nie działa, pomijam wiadomość dla wyszukiwania #{$search->id}.");
        }
    }
}
