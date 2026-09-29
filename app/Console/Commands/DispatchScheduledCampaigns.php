<?php

namespace App\Console\Commands;

use App\Events\CampaignUpdated;
use App\Jobs\SendCampaignNotification;
use App\Models\NotificationCampaign;
use App\Services\Campaigns\CampaignThrottle;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('campaigns:dispatch-scheduled')]
#[Description('Envoie les campagnes programmées arrivées à échéance')]
class DispatchScheduledCampaigns extends Command
{
    public function handle(CampaignThrottle $throttle): void
    {
        $due = NotificationCampaign::where('status', 'scheduled')
            ->whereNull('archived_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        $sent = 0;
        $deferred = 0;

        foreach ($due as $campaign) {
            $clientIds = collect($campaign->target['recipient_client_ids'] ?? []);
            $restaurant = $campaign->restaurant;

            // Si l'établissement n'existe plus, ou si son service FCM est suspendu par l'administration,
            // ou si le plafond quotidien est atteint : on bloque l'envoi et on repousse au prochain créneau.
            if (! $restaurant || $restaurant->isFcmSuspended() || $clientIds->count() > $throttle->remainingToday($restaurant)) {
                $campaign->update(['scheduled_at' => $throttle->nextWindowStart()]);
                $deferred++;

                continue;
            }

            foreach ($clientIds as $clientId) {
                SendCampaignNotification::dispatch($campaign->id, $clientId);
            }

            $campaign->update(['status' => 'sent', 'sent_at' => now()]);
            event(new CampaignUpdated($campaign));

            if ($restaurant) {
                app(NotificationDispatcher::class)->send(
                    $restaurant,
                    'merchant_campaign_sent',
                    'Campagne envoyée 📨',
                    "Votre campagne « {$campaign->title} » a été publiée à {$clientIds->count()} destinataire(s).",
                    ['campaign_id' => $campaign->id],
                );
            }

            $sent++;
        }

        $this->info("{$sent} campagne(s) envoyée(s), {$deferred} repoussée(s) (plafond quotidien atteint).");
    }
}
