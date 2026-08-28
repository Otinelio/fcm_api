<?php

namespace App\Events;

use App\Models\LoyaltyReward;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Diffusé à chaque transition d'état d'une récompense (déblocage, validation
 * marchand, annulation) — permet à l'écran "Mes récompenses" ET au dashboard
 * marchand de se mettre à jour en direct, sans pull-to-refresh (voir
 * `MivaFid-doc/recompense.md` section 13). Réutilise les mêmes canaux privés
 * que `LoyaltyCardUpdated` (`loyalty.{clientId}` + `merchant.{restaurantId}`,
 * déjà autorisés dans `routes/channels.php`) plutôt que d'en ouvrir d'autres.
 */
class LoyaltyRewardUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public LoyaltyReward $reward)
    {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [];

        // Une carte supprimée après coup laisse `loyalty_card_id` à null
        // (nullOnDelete, voir la migration) : rien à diffuser côté client,
        // pas d'erreur.
        if (($clientId = $this->reward->loyaltyCard?->client_id) !== null) {
            $channels[] = new PrivateChannel('loyalty.' . $clientId);
        }

        // `restaurant_id` est une colonne propre de `loyalty_rewards`
        // (jamais nulle), indépendante de la relation `loyaltyCard`.
        $channels[] = new PrivateChannel('merchant.' . $this->reward->restaurant_id);

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'loyalty.reward.updated';
    }

    public function broadcastWith(): array
    {
        $tier = $this->reward->programTier;
        $tierService = app(\App\Services\Loyalty\LoyaltyTierService::class);
        $position = null;
        $icon_key = null;

        if ($tier) {
            $tiers = $tierService->tiers($tier->loyaltyProgram);
            foreach ($tiers as $t) {
                if ($t['id'] === $tier->id) {
                    $position = $t['position'];
                    $icon_key = $t['icon_key'];
                    break;
                }
            }
        }

        return [
            'id'              => $this->reward->id,
            // Permet au dashboard marchand de filtrer les événements par
            // carte (ex. fiche client ouverte) sans recharger pour tout
            // client — le client mobile, lui, n'a pas besoin de filtrer
            // (une seule liste "Mes récompenses" toutes cartes confondues).
            'loyalty_card_id' => $this->reward->loyalty_card_id,
            'status'          => $this->reward->status,
            'program_tier_id' => $this->reward->program_tier_id,
            'level_name'      => $tier?->level_name,
            'position'        => $position,
            'icon_key'        => $icon_key,
        ];
    }
}
