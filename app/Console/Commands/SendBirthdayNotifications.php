<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\User;
use App\Jobs\SendPromoNotification;

#[Signature('notifications:birthdays')]
#[Description('Send birthday notifications to users whose birthday is today')]
class SendBirthdayNotifications extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $users = User::whereMonth('birthday', now()->month)
            ->whereDay('birthday', now()->day)
            ->get();

        $count = 0;

        $users->each(function (User $user) use (&$count) {
            foreach ($user->deviceTokens as $deviceToken) {
                SendPromoNotification::dispatch($user->id, $deviceToken->token, [
                    'title' => 'Happy Birthday 🎂',
                    'body'  => 'Un dessert offert t\'attend cette semaine !',
                ]);
                $count++;
            }
        });

        $this->info("Sent {$count} birthday notification(s) to {$users->count()} user(s).");
    }
}
