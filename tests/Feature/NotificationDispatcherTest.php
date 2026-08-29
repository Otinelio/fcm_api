<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Notification;
use App\Models\Restaurant;
use App\Services\Fcm\FcmService;
use App\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_creates_in_app_row_and_pushes_when_type_allows_push(): void
    {
        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone' => '+22890000102',
            'password' => bcrypt('secret123'),
        ]);
        $client->deviceTokens()->create(['token' => 'tok-client-1', 'platform' => 'android']);

        $this->mock(FcmService::class, function ($mock) use ($client) {
            $mock->shouldReceive('sendToToken')
                ->once()
                ->with(
                    'tok-client-1',
                    ['title' => 'Récompense débloquée 🎁', 'body' => 'Café offert'],
                    ['type' => 'reward_unlocked', 'reward_id' => 42],
                    $client->id,
                    'reward_unlocked'
                )
                ->andReturn(true);
        });

        $notification = app(NotificationDispatcher::class)->send(
            $client,
            'reward_unlocked',
            'Récompense débloquée 🎁',
            'Café offert',
            ['reward_id' => 42],
        );

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'notifiable_type' => $client->getMorphClass(),
            'notifiable_id' => $client->id,
            'type' => 'reward_unlocked',
            'title' => 'Récompense débloquée 🎁',
        ]);
    }

    public function test_send_records_in_app_only_and_skips_push_for_types_without_push(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa',
            'category' => 'Restaurant',
            'email' => 'commerce-dispatcher@example.com',
            'password' => bcrypt('password123'),
        ]);
        $restaurant->deviceTokens()->create(['token' => 'tok-restaurant-1', 'platform' => 'android']);

        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldNotReceive('sendToToken');
        });

        app(NotificationDispatcher::class)->send(
            $restaurant,
            'merchant_new_client',
            'Nouveau client 👋',
            'Ada a rejoint votre programme de fidélité.',
        );

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $restaurant->getMorphClass(),
            'notifiable_id' => $restaurant->id,
            'type' => 'merchant_new_client',
        ]);
    }

    public function test_record_only_never_pushes_even_for_a_push_enabled_type(): void
    {
        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Bo',
            'phone' => '+22890000103',
            'password' => bcrypt('secret123'),
        ]);
        $client->deviceTokens()->create(['token' => 'tok-client-2', 'platform' => 'ios']);

        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldNotReceive('sendToToken');
        });

        app(NotificationDispatcher::class)->recordOnly(
            $client,
            'campaign',
            'Campagne',
            'Message',
        );

        $this->assertSame(1, Notification::where('type', 'campaign')->count());
    }
}
