<?php

namespace App\Listeners;

use App\Events\RewardUnlocked;
use App\Jobs\SendPromoNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendRewardNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct()
    {
        //
    }

    public function handle(RewardUnlocked $event): void
    {
        foreach ($event->card->user->deviceTokens as $deviceToken) {
            SendPromoNotification::dispatch(
                $event->card->user_id,
                $deviceToken->token,
                ['title' => 'Free Dessert Unlocked 🎉', 'body' => 'Ton dessert offert t’attend en restaurant !']
            );
        }
    }
}
