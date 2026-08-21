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
 * marchand, annulation) — permet à l'écran "Mes récompenses" de se mettre à
 * jour en direct, sans pull-to-refresh (voir `MivaFid-doc/recompense.md`
 * section 13). Réutilise le canal privé de `LoyaltyCardUpdated`
 * (`loyalty.{clientId}`, déjà autorisé dans `routes/channels.php` et déjà
 * ouvert côté app dès l'authentification) plutôt que d'en ouvrir un second.
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
        $clientId = $this->reward->loyaltyCard?->client_id;

        // Une carte supprimée après coup laisse `loyalty_card_id` à null
        // (nullOnDelete, voir la migration) : rien à diffuser, pas d'erreur.
        return $clientId === null ? [] : [new PrivateChannel('loyalty.' . $clientId)];
    }

    public function broadcastAs(): string
    {
        return 'loyalty.reward.updated';
    }

    public function broadcastWith(): array
    {
        $tier = $this->reward->programTier;

        return [
            'id'              => $this->reward->id,
            'status'          => $this->reward->status,
            'program_tier_id' => $this->reward->program_tier_id,
            'level_name'      => $tier?->level_name,
            'icon'            => $tier
                ? app(\App\Services\Loyalty\LoyaltyTierService::class)->iconForRank($tier->order)
                : null,
        ];
    }
}
