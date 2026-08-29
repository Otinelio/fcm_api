<?php

namespace App\Events;

use App\Models\Client;
use App\Models\Notification;
use App\Models\Restaurant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Diffusé à chaque ligne créée dans `notifications` (centre de
 * notifications unifié) — permet à la cloche/liste in-app de se mettre à
 * jour en direct au lieu d'attendre le prochain chargement manuel de
 * l'écran. Réutilise les mêmes canaux privés que `LoyaltyCardUpdated`/
 * `LoyaltyRewardUpdated` (`loyalty.{clientId}` / `merchant.{restaurantId}`,
 * déjà autorisés dans `routes/channels.php`).
 */
class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Notification $notification)
    {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $notifiable = $this->notification->notifiable;

        return match (true) {
            $notifiable instanceof Client => [new PrivateChannel('loyalty.' . $notifiable->id)],
            $notifiable instanceof Restaurant => [new PrivateChannel('merchant.' . $notifiable->id)],
            default => [],
        };
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'type' => $this->notification->type,
            'title' => $this->notification->title,
            'body' => $this->notification->body,
            'data' => $this->notification->data,
            'created_at' => $this->notification->created_at,
        ];
    }
}
