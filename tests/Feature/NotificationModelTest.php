<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_belongs_to_a_polymorphic_recipient_and_casts_data(): void
    {
        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone' => '+22890000101',
            'password' => bcrypt('secret123'),
        ]);

        $notification = Notification::create([
            'notifiable_type' => $client->getMorphClass(),
            'notifiable_id' => $client->id,
            'type' => 'reward_unlocked',
            'title' => 'Récompense débloquée 🎁',
            'body' => 'Récompense débloquée : Café offert',
            'data' => ['reward_id' => 42],
        ]);

        $this->assertTrue($notification->notifiable->is($client));
        $this->assertSame(42, $notification->data['reward_id']);
        $this->assertNull($notification->read_at);
        $this->assertSame(1, Notification::unread()->count());

        $notification->update(['read_at' => now()]);
        $this->assertSame(0, Notification::unread()->count());
    }
}
