<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LoyaltyPointAdded implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public User $customer) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('loyalty.'.$this->customer->id),
            new Channel('loyalty.'.$this->customer->id),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'customer_id' => $this->customer->id,
            'loyalty_points' => $this->customer->loyalty_points,
        ];
    }
}
