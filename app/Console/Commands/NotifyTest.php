<?php

namespace App\Console\Commands;

use App\Notifications\Notifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('notify:test {channel=discord : discord}')]
#[Description('Wysyła wiadomość testową na kanał')]
class NotifyTest extends Command
{
    public function handle(Notifier $notifier): int
    {
        try {
            $notifier->test($this->argument('channel'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Wysłano. Sprawdź kanał.');

        return self::SUCCESS;
    }
}
