<?php

namespace App\Events;

use App\Models\NotificationCampaign;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CampaignUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public NotificationCampaign $campaign)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('merchant.' . $this->campaign->restaurant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'campaign.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->campaign->id,
            'status' => $this->campaign->status,
        ];
    }
}
