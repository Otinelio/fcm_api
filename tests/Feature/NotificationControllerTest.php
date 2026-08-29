<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Notification;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithToken(): array
    {
        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone' => '+228'.random_int(10000000, 99999999),
            'password' => bcrypt('secret123'),
        ]);

        return [$client, $client->createToken('mobile-app')->plainTextToken];
    }

    private function notificationFor($recipient, string $type = 'reward_unlocked'): Notification
    {
        return Notification::create([
            'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => $recipient->id,
            'type' => $type,
            'title' => 'Titre',
            'body' => 'Corps',
        ]);
    }

    public function test_client_lists_only_their_own_notifications(): void
    {
        [$client, $token] = $this->clientWithToken();
        [$other] = $this->clientWithToken();
        $this->notificationFor($client);
        $this->notificationFor($other);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/notifications');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_unread_count(): void
    {
        [$client, $token] = $this->clientWithToken();
        $this->notificationFor($client);
        $this->notificationFor($client)->update(['read_at' => now()]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/notifications/unread-count');

        $response->assertOk();
        $response->assertJsonPath('unread_count', 1);
    }

    public function test_mark_read_rejects_another_users_notification(): void
    {
        [$client, $token] = $this->clientWithToken();
        [$other] = $this->clientWithToken();
        $notification = $this->notificationFor($other);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/notifications/{$notification->id}/read")
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_read_and_mark_all_read(): void
    {
        [$client, $token] = $this->clientWithToken();
        $a = $this->notificationFor($client);
        $b = $this->notificationFor($client);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/notifications/{$a->id}/read")
            ->assertOk();
        $this->assertNotNull($a->fresh()->read_at);
        $this->assertNull($b->fresh()->read_at);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/notifications/read-all')
            ->assertOk();
        $this->assertNotNull($b->fresh()->read_at);
    }

    public function test_delete_and_delete_all(): void
    {
        [$client, $token] = $this->clientWithToken();
        $a = $this->notificationFor($client);
        $b = $this->notificationFor($client);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/notifications/{$a->id}")
            ->assertOk();
        $this->assertDatabaseMissing('notifications', ['id' => $a->id]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/notifications')
            ->assertOk();
        $this->assertDatabaseMissing('notifications', ['id' => $b->id]);
    }

    public function test_merchant_endpoints_use_the_same_controller_scoped_to_the_restaurant(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa',
            'category' => 'Restaurant',
            'email' => 'commerce-notif-api@example.com',
            'password' => bcrypt('password123'),
        ]);
        $this->notificationFor($restaurant, 'merchant_new_client');
        $merchantToken = $restaurant->createToken('merchant-app')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$merchantToken}")
            ->getJson('/api/merchant/notifications');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }
}
