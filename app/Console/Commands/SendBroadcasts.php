<?php

namespace App\Console\Commands;

use App\Models\Broadcast;
use App\Services\Broadcasts\BroadcastSender;
use Illuminate\Console\Command;

/**
 * Sends the next small batch of every broadcast that is still going out.
 */
class SendBroadcasts extends Command
{
    protected $signature = 'broadcasts:send';

    protected $description = 'Send the next batch of each running broadcast';

    public function handle(BroadcastSender $sender): int
    {
        $sent = 0;

        foreach (Broadcast::withoutTenantScope()->where('status', Broadcast::SENDING)->orderBy('id')->get() as $broadcast) {
            // One broadcast failing must not stop the others.
            try {
                $sent += $sender->run($broadcast);
            } catch (\Throwable $e) {
                $this->error("Broadcast {$broadcast->id}: {$e->getMessage()}");
            }
        }

        $this->info("Sent {$sent} message(s).");

        return self::SUCCESS;
    }
}
