<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Events\RewardUnlocked;
use App\Jobs\SendRewardFcmFallback;
use App\Models\Client;
use App\Models\Notification;
use App\Models\Reward;
use App\Models\RewardNotificationLog;
use App\Services\Fcm\FcmService;
use Illuminate\Database\Eloquent\Model;

class NotificationDispatcher
{
    /**
     * Politique fixe par type d'événement — voir
     * `docs/superpowers/specs/2026-08-29-notifications-unifiees-design.md`.
     * `true` : push FCM + ligne in-app. `false` : ligne in-app seule.
     */
    private const PUSH_ENABLED_TYPES = [
        'reward_unlocked' => true,
        'referral_pending' => true,
        'referral_validated' => true,
        'birthday' => true,
        'campaign' => true,
        'admin_broadcast' => true,
        'cashback_received' => true,
        'cashback_redeemed' => true,
        'level_up' => true,
        'stamp_added' => true,
        'points_added' => true,
        'stamp_removed' => true,
        'points_removed' => true,
        'merchant_new_client' => true,
        'merchant_low_sms' => true,
        'merchant_weekly_report' => true,
        'merchant_new_review' => true,
        'merchant_campaign_sent' => true,
        'merchant_birthday_reward' => true,
        'merchant_referral_new' => true,
        'merchant_referral_valid' => true,
        'merchant_sms_low' => true,
        'merchant_sms_depleted' => true,
        'fraud_alert' => true,
        'proximity_alert' => true,
    ];

    public function __construct(
        protected PresenceChecker $presenceChecker,
        protected FcmService $fcm,
    ) {}

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

    /**
     * Point de passage unique pour toute notification utilisateur : crée
     * toujours la ligne in-app, et pousse un FCM à chaque appareil du
     * destinataire si la politique du type l'autorise (voir
     * PUSH_ENABLED_TYPES).
     */
    public function send(Model $recipient, string $type, string $title, string $body, array $data = []): ?Notification
    {
        if ($recipient instanceof \App\Models\Restaurant) {
            $prefs = $recipient->notification_preferences ?? [];
            if ($type === 'merchant_new_client' && isset($prefs['new_client']) && ! $prefs['new_client']) {
                return null;
            }
            if (in_array($type, ['merchant_low_sms', 'merchant_sms_low', 'merchant_sms_depleted'], true) && isset($prefs['low_sms']) && ! $prefs['low_sms']) {
                return null;
            }
            if (in_array($type, ['merchant_birthday_reward', 'merchant_reward_unlocked'], true) && isset($prefs['reward']) && ! $prefs['reward']) {
                return null;
            }
        }

        $notification = $this->recordOnly($recipient, $type, $title, $body, $data);

        if (self::PUSH_ENABLED_TYPES[$type] ?? false) {
            $this->pushToRecipient($recipient, $type, $title, $body, array_merge($data, ['notification_id' => (string) $notification->id]));
        }

        return $notification;
    }

    /**
     * Crée uniquement la ligne in-app, sans jamais pousser — pour les
     * appelants qui gèrent déjà leur propre envoi push (ex. les campagnes
     * marchand, qui journalisent aussi dans `notification_logs`).
     */
    public function recordOnly(Model $recipient, string $type, string $title, string $body, array $data = []): Notification
    {
        $notification = Notification::create([
            'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => $recipient->getKey(),
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        event(new NotificationCreated($notification));

        return $notification;
    }

    private function pushToRecipient(Model $recipient, string $type, string $title, string $body, array $data): void
    {
        $clientId = $recipient instanceof Client ? $recipient->id : null;
        $notificationPayload = ['title' => $title, 'body' => $body];
        $dataPayload = array_merge(['type' => $type], $data);

        foreach ($recipient->deviceTokens as $deviceToken) {
            \App\Jobs\SendPushNotification::dispatch(
                $deviceToken->token,
                $notificationPayload,
                $dataPayload,
                $clientId,
                $type,
            );
        }
    }
}
