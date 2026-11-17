<?php

namespace App\Listeners;

use App\Events\PortalBlockedRepeatedly;
use App\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPortalBlockedAlert implements ShouldQueue
{
    public string $queue = 'notify';

    public function __construct(private Notifier $notifier) {}

    public function handle(PortalBlockedRepeatedly $event): void
    {
        $this->notifier->alert(
            "portal-blocked:{$event->portal}",
            "Portal {$event->portal} blokuje zapytania",
            "{$event->consecutiveBlocks} blokady z rzędu. Portal jest wstrzymany na ".config('scrapers.block_hours')." h i wróci sam.\n\nOstatni błąd: {$event->lastMessage}",
        );
    }
}
