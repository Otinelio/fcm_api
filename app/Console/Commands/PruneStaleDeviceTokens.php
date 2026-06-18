<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\DeviceToken;

#[Signature('tokens:prune')]
#[Description('Prune device tokens that have not been used in 60 days')]
class PruneStaleDeviceTokens extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $deleted = DeviceToken::where('last_used_at', '<', now()->subDays(60))->delete();
        $this->info("Deleted {$deleted} stale device tokens.");
    }
}
