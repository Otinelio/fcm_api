<?php

namespace App\Events;

use App\Models\Reward;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RewardUnlocked implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(public Reward $reward)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('customer.' . $this->reward->customer_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'reward.unlocked';
    }

    public function broadcastWith(): array
    {
        return [
            'reward_id' => $this->reward->id,
            'title' => $this->reward->title,
            'description' => $this->reward->description,
            'unlocked_at' => $this->reward->unlocked_at->toIso8601String(),
        ];
    }
}
