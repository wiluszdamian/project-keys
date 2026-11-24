<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Notifications\PanelNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('viewings:remind')]
#[Description('Przypomnienie w panelu o oglądaniu na kilka godzin przed terminem')]
class ViewingsRemind extends Command
{
    public function handle(PanelNotifier $panel): int
    {
        $listings = Listing::query()
            ->whereNull('viewing_reminded_at')
            ->whereBetween('viewing_at', [now(), now()->addMinutes((int) config('notify.viewing_reminder_minutes'))])
            ->get();

        foreach ($listings as $listing) {
            $panel->viewingReminder($listing);
            $listing->forceFill(['viewing_reminded_at' => now()])->save();
        }

        $this->info("Przypomnienia: {$listings->count()}.");

        return self::SUCCESS;
    }
}
