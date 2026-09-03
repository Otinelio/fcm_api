<?php

namespace App\Console\Commands;

use App\Jobs\SendCampaignNotification;
use App\Models\NotificationCampaign;
use App\Services\Campaigns\CampaignThrottle;
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

            // Le plafond quotidien peut avoir été atteint entre-temps par
            // d'autres campagnes du même commerce : on ne dépasse jamais la
            // limite, on repousse simplement à l'ouverture du lendemain.
            if (! $restaurant || $clientIds->count() > $throttle->remainingToday($restaurant)) {
                // `nextWindowStart()` (sans argument = depuis maintenant) : si on
                // est déjà dans la plage (cas normal ici, cette commande ne
                // tourne que pendant les heures d'envoi), elle renvoie demain
                // 8h — jamais aujourd'hui, sinon la même campagne repasserait
                // due dans la minute et re-dépasserait le plafond en boucle.
                $campaign->update(['scheduled_at' => $throttle->nextWindowStart()]);
                $deferred++;
                continue;
            }

            foreach ($clientIds as $clientId) {
                SendCampaignNotification::dispatch($campaign->id, $clientId);
            }

            $campaign->update(['status' => 'sent', 'sent_at' => now()]);
            event(new \App\Events\CampaignUpdated($campaign));

            if ($restaurant) {
                app(\App\Services\NotificationDispatcher::class)->send(
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
