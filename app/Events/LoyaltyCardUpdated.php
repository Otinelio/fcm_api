<?php

namespace App\Events;

use App\Models\LoyaltyCard;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Diffusé après chaque validation marchand (tampon/point/montant) — permet
 * au wallet client de se mettre à jour en direct, sans pull-to-refresh.
 * Canal privé `loyalty.{clientId}` déjà autorisé dans routes/channels.php
 * (à ne pas confondre avec `App\Events\StampAdded`/`LoyaltyPointAdded`,
 * squelettes de prototype jamais branchés, laissés tels quels).
 */
class LoyaltyCardUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public LoyaltyCard $card)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('loyalty.' . $this->card->client_id)];
    }

    public function broadcastAs(): string
    {
        return 'loyalty.card.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id'                    => $this->card->id,
            'progress'              => $this->card->progress,
            'cashback_balance_fcfa' => $this->card->cashback_available_fcfa,
            'status'                => $this->card->status,
            'reward_unlocked'       => $this->card->status === 'reward_available',
            // Mêmes accesseurs que le fetch initial (`LoyaltyCard::$appends`)
            // — le payload temps réel est autosuffisant, plus besoin de
            // reconcilier avec le fetch initial pour rester à jour.
            'goal'                  => $this->card->goal,
            'percent'               => $this->card->percent,
            'level'                 => $this->card->level,
            'tiers'                 => $this->card->tiers,
        ];
    }
}
