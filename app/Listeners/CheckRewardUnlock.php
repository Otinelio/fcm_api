<?php

namespace App\Listeners;

use App\Events\StampAdded;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Events\RewardUnlocked;

class CheckRewardUnlock
{
    public function __construct()
    {
        //
    }

    public function handle(StampAdded $event): void
    {
        if ($event->card->stamps >= $event->card->required_stamps) {
            $event->card->update(['reward_unlocked_at' => now()]);
            
            // Création de la récompense
            $reward = \App\Models\Reward::create([
                'customer_id' => $event->card->user_id,
                'title' => 'Cadeau de fidélité',
                'description' => 'Bravo, tu as atteint ton palier de fidélité !',
                'unlocked_at' => now(),
            ]);

            event(new RewardUnlocked($reward));
        }
    }
}
