<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Kilka blokad portalu z rzędu. W fazie 7 wysyła alert.
 */
class PortalBlockedRepeatedly
{
    use Dispatchable;

    public function __construct(
        public string $portal,
        public int $consecutiveBlocks,
        public string $lastMessage,
    ) {}
}
