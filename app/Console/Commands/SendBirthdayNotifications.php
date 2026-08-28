<?php

namespace App\Console\Commands;

use App\Events\LoyaltyRewardUpdated;
use App\Jobs\SendPromoNotification;
use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyReward;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Crée la récompense anniversaire du jour, pour chaque carte d'un client
 * dont c'est l'anniversaire, sur chaque programme qui l'a activée
 * (`loyalty_programs.config['birthday_reward']`, réglable depuis les
 * réglages marchand).
 *
 * Corrige un no-op silencieux : la version précédente interrogeait
 * `App\Models\User` (table jamais peuplée — les comptes réels sont
 * `Client`/`Restaurant`) et ne créait aucune récompense, seulement une
 * notification au texte figé.
 */
#[Signature('notifications:birthdays')]
#[Description("Crée la récompense anniversaire et notifie chaque client dont c'est l'anniversaire aujourd'hui")]
class SendBirthdayNotifications extends Command
{
    public function handle(): void
    {
        $clients = Client::whereNotNull('birthdate')
            ->whereMonth('birthdate', now()->month)
            ->whereDay('birthdate', now()->day)
            ->get();

        $rewardsCreated = 0;
        $notificationsSent = 0;

        foreach ($clients as $client) {
            $cards = LoyaltyCard::where('client_id', $client->id)
                ->with(['loyaltyProgram', 'restaurant'])
                ->get();

            foreach ($cards as $card) {
                $program = $card->loyaltyProgram;
                $config = $program?->config['birthday_reward'] ?? null;

                if (! $program || ! ($config['enabled'] ?? false)) {
                    continue;
                }

                // Une seule récompense anniversaire par carte et par an, même
                // si la commande est relancée le même jour.
                $alreadyRewarded = LoyaltyReward::where('loyalty_card_id', $card->id)
                    ->where('source', 'birthday')
                    ->whereYear('unlocked_at', now()->year)
                    ->exists();
                if ($alreadyRewarded) {
                    continue;
                }

                $title = $config['title'] ?: 'Joyeux anniversaire 🎂';
                $validityDays = $config['validity_days'] ?? null;

                $reward = LoyaltyReward::create([
                    'loyalty_card_id' => $card->id,
                    'restaurant_id' => $card->restaurant_id,
                    'source' => 'birthday',
                    'title' => $title,
                    'expires_at' => $validityDays ? now()->addDays((int) $validityDays) : null,
                ]);
                $rewardsCreated++;

                LoyaltyRewardUpdated::dispatch($reward->load('loyaltyCard'));

                foreach ($client->deviceTokens as $deviceToken) {
                    SendPromoNotification::dispatch($client->id, $deviceToken->token, [
                        'title' => 'Joyeux anniversaire 🎂',
                        'body' => $title.' vous attend chez '.($card->restaurant->name ?? 'votre commerce préféré').' !',
                    ]);
                    $notificationsSent++;
                }
            }
        }

        $this->info("Récompenses anniversaire créées : {$rewardsCreated}. Notifications envoyées : {$notificationsSent}.");
    }
}
