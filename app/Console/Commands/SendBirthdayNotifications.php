<?php

namespace App\Console\Commands;

use App\Events\LoyaltyRewardUpdated;
use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyReward;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Crée la récompense anniversaire, pour chaque carte d'un client dont
 * l'anniversaire tombe dans les 30 prochains jours, sur chaque programme qui
 * l'a activée (`loyalty_programs.config['birthday_reward']`, réglable depuis
 * les réglages marchand) — donne au client un mois pour en profiter plutôt
 * que de la faire apparaître (et potentiellement expirer inaperçue) le seul
 * jour J.
 *
 * Corrige un no-op silencieux : la version précédente interrogeait
 * `App\Models\User` (table jamais peuplée — les comptes réels sont
 * `Client`/`Restaurant`) et ne créait aucune récompense, seulement une
 * notification au texte figé.
 */
#[Signature('notifications:birthdays')]
#[Description("Crée la récompense anniversaire pour chaque client dont l'anniversaire tombe dans les 30 prochains jours")]
class SendBirthdayNotifications extends Command
{
    private const WINDOW_DAYS = 30;

    /**
     * Marge de déduplication : strictement inférieure à un an (365 j) pour
     * ne jamais confondre deux occurrences successives, mais large pour
     * rester fiable même si la commande tourne en retard un jour donné —
     * une seule récompense anniversaire par carte tous les ~11 mois.
     */
    private const DEDUPLICATION_WINDOW_DAYS = 335;

    public function handle(NotificationDispatcher $notifications): void
    {
        $clients = Client::whereNotNull('birthdate')->get()
            ->filter(fn (Client $client) => $this->daysUntilNextBirthday($client->birthdate) <= self::WINDOW_DAYS);

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

                // Une seule récompense anniversaire par carte tous les ~11
                // mois, même si la commande est relancée dans la fenêtre.
                $alreadyRewarded = LoyaltyReward::where('loyalty_card_id', $card->id)
                    ->where('source', 'birthday')
                    ->where('unlocked_at', '>=', now()->subDays(self::DEDUPLICATION_WINDOW_DAYS))
                    ->exists();
                if ($alreadyRewarded) {
                    continue;
                }

                $title = $config['title'] ?: 'Joyeux anniversaire 🎂';
                $isSurprise = (bool) ($config['surprise'] ?? false);
                $validityDays = $config['validity_days'] ?? null;

                $reward = LoyaltyReward::create([
                    'loyalty_card_id' => $card->id,
                    'restaurant_id' => $card->restaurant_id,
                    'source' => 'birthday',
                    'is_surprise' => $isSurprise,
                    // Toujours le vrai titre en base — le marchand doit
                    // savoir quoi remettre en boutique. Le masquage côté
                    // client se fait à la lecture (LoyaltyRewardController).
                    'title' => $title,
                    'expires_at' => $validityDays ? now()->addDays((int) $validityDays) : null,
                ]);
                $rewardsCreated++;

                LoyaltyRewardUpdated::dispatch($reward->load('loyaltyCard'));

                $notificationBody = $isSurprise
                    ? 'Une surprise vous attend chez '.($card->restaurant->name ?? 'votre commerce préféré').' pour votre anniversaire !'
                    : $title.' vous attend chez '.($card->restaurant->name ?? 'votre commerce préféré').' !';

                $notifications->send($client, 'birthday', 'Joyeux anniversaire 🎂', $notificationBody);
                $notificationsSent++;
            }
        }

        $this->info("Récompenses anniversaire créées : {$rewardsCreated}. Notifications envoyées : {$notificationsSent}.");
    }

    /** Nombre de jours avant la prochaine occurrence de cette date de naissance (0 si aujourd'hui). */
    private function daysUntilNextBirthday(Carbon $birthdate): int
    {
        $today = now()->startOfDay();
        $next = $birthdate->copy()->year($today->year)->startOfDay();
        if ($next->lt($today)) {
            $next = $next->addYear();
        }

        return $today->diffInDays($next);
    }
}
