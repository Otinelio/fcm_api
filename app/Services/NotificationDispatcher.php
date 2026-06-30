<?php

namespace App\Services;

use App\Events\RewardUnlocked;
use App\Models\Reward;
use App\Models\RewardNotificationLog;
use App\Jobs\SendRewardFcmFallback;

class NotificationDispatcher
{
    public function __construct(protected PresenceChecker $presenceChecker)
    {
    }

    public function dispatchRewardUnlocked(Reward $reward): void
    {
        // Étape 1 : diffusion immédiate via Reverb, qu'il y ait
        // quelqu'un en écoute ou pas (ça ne coûte rien)
        event(new RewardUnlocked($reward));

        RewardNotificationLog::create([
            'reward_id' => $reward->id,
            'channel' => 'reverb',
            'status' => 'sent',
        ]);

        // Étape 2 : ajustement du délai de fallback selon la présence
        // détectée à cet instant précis. Ce n'est qu'une optimisation
        // de timing — l'ack reste la seule vraie preuve de réception.
        $isOnline = $this->presenceChecker->isCustomerOnline($reward->customer_id);
        $fallbackDelay = $isOnline ? 6 : 1;

        SendRewardFcmFallback::dispatch($reward->id)
            ->delay(now()->addSeconds($fallbackDelay));
    }
}
