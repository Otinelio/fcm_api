<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\NotificationCampaign;
use App\Models\NotificationLog;
use App\Services\Fcm\FcmService;
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

    public function handle(FcmService $fcm): void
    {
        $campaign = NotificationCampaign::find($this->campaignId);
        $client = Client::with('deviceTokens')->find($this->clientId);

        if (! $campaign || ! $client) {
            return;
        }

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
                $success = $fcm->sendToToken(
                    $deviceToken->token,
                    ['title' => $campaign->title, 'body' => $campaign->message],
                    ['type' => 'campaign', 'campaign_id' => (string) $campaign->id],
                    null,
                    'campaign'
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
}
