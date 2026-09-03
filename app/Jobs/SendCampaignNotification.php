<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\NotificationCampaign;
use App\Models\NotificationLog;
use App\Services\Fcm\FcmService;
use App\Services\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendCampaignNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public int $campaignId,
        public int $clientId,
    ) {
    }

    public function handle(FcmService $fcm, NotificationDispatcher $notifications): void
    {
        $campaign = NotificationCampaign::with('restaurant')->find($this->campaignId);
        $client = Client::with('deviceTokens')->find($this->clientId);

        if (! $campaign || ! $client) {
            return;
        }

        // Le titre principal de la notification est le nom de l'établissement,
        // pas le titre de la campagne — le marchand veut que le client voie
        // immédiatement qui lui envoie le message.
        $restaurantName = $campaign->restaurant?->name ?? '';
        $notifTitle = $restaurantName ?: ($campaign->title ?? '');
        $notifBody = $this->buildNotifBody($campaign->title, $campaign->message);

        $campaignData = [
            'campaign_id' => $campaign->id,
            'campaign_type' => $campaign->type,
            'restaurant_name' => $restaurantName,
        ];
        if ($campaign->image_url) {
            $campaignData['image_url'] = $campaign->image_url;
        }

        $card = \App\Models\LoyaltyCard::where('client_id', $client->id)
            ->where('restaurant_id', $campaign->restaurant_id)
            ->first();

        if ($card) {
            $campaignData['card_id'] = (string) $card->id;
            if ($campaign->type === 'reward') {
                $reward = \App\Models\LoyaltyReward::where('loyalty_card_id', $card->id)
                    ->where('status', 'available')
                    ->orderByDesc('created_at')
                    ->first();
                if ($reward) {
                    $campaignData['reward_id'] = (string) $reward->id;
                }
            }
        }

        $notifications->recordOnly(
            $client,
            'campaign',
            $notifTitle,
            $notifBody,
            $campaignData,
        );

        if ($client->deviceTokens->isEmpty()) {
            NotificationLog::create([
                'notification_campaign_id' => $campaign->id,
                'client_id' => $client->id,
                'restaurant_id' => $campaign->restaurant_id,
                'channel' => 'fcm',
                'status' => 'failed',
                'failure_reason' => 'no_device_token',
                'sent_at' => now(),
            ]);

            return;
        }

        $sent = false;
        $failureReason = 'fcm_send_failed';

        try {
            foreach ($client->deviceTokens as $deviceToken) {
                $fcmData = [
                    'type' => 'campaign',
                    'campaign_id' => (string) $campaign->id,
                    'campaign_type' => $campaign->type,
                    'restaurant_name' => $restaurantName,
                ];
                if ($campaign->image_url) {
                    $fcmData['image_url'] = $campaign->image_url;
                }
                if ($card) {
                    $fcmData['card_id'] = (string) $card->id;
                    if (isset($campaignData['reward_id'])) {
                        $fcmData['reward_id'] = $campaignData['reward_id'];
                    }
                }
                $success = $fcm->sendToToken(
                    $deviceToken->token,
                    ['title' => $notifTitle, 'body' => $notifBody],
                    $fcmData,
                    $client->id,
                    'campaign',
                    $campaign->image_url
                );

                if ($success) {
                    $sent = true;
                }
            }
        } catch (Throwable $e) {
            // Une erreur de config (ex: mauvais projet Firebase côté
            // credentials) throw au lieu de renvoyer `false` — sans ce
            // catch, le job crashe silencieusement (aucune ligne dans
            // `notification_logs`, juste des tentatives perdues dans
            // `failed_jobs`) et la campagne reste indéfiniment "envoyée"
            // sans qu'aucun destinataire n'apparaisse en échec.
            $failureReason = 'exception: ' . $e->getMessage();
            Log::error('SendCampaignNotification: envoi FCM en erreur', [
                'campaign_id' => $campaign->id,
                'client_id' => $client->id,
                'exception' => $e->getMessage(),
            ]);
        }

        NotificationLog::create([
            'notification_campaign_id' => $campaign->id,
            'client_id' => $client->id,
            'restaurant_id' => $campaign->restaurant_id,
            'channel' => 'fcm',
            'status' => $sent ? 'sent' : 'failed',
            'failure_reason' => $sent ? null : $failureReason,
            'sent_at' => now(),
        ]);
    }

    /**
     * Construit le corps de la notification en combinant titre de campagne et
     * message. Le titre de la campagne apparaît en gras dans le body (le
     * titre de la notif push étant réservé au nom de l'établissement).
     */
    private function buildNotifBody(?string $campaignTitle, ?string $campaignMessage): string
    {
        $title = trim($campaignTitle ?? '');
        $message = trim($campaignMessage ?? '');

        if ($title !== '' && $message !== '') {
            return "{$title} — {$message}";
        }

        return $title !== '' ? $title : $message;
    }
}
