<?php

namespace App\Jobs;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\Search;
use App\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendListingNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public Search $search, public Listing $listing)
    {
        $this->onQueue('notify');
    }

    public function handle(Notifier $notifier): void
    {
        $row = DB::table('search_listing')
            ->where('search_id', $this->search->id)
            ->where('listing_id', $this->listing->id);

        // notified_at dopiero po udanej wysyłce, żeby nie dublować i nie gubić.
        if ((clone $row)->whereNotNull('notified_at')->exists()) {
            return;
        }

        if ($this->listing->status === ListingStatus::Rejected) {
            $row->update(['notify_skipped_reason' => 'rejected']);

            return;
        }

        if ($this->listing->wasNotified()) {
            $row->update(['notify_skipped_reason' => 'already_sent']);

            return;
        }

        $analysis = $this->listing->analyses()->where('search_id', $this->search->id)->first();
        $notifier->listing($this->search, $this->listing, $analysis);

        $row->update(['notified_at' => now()]);
    }
}
