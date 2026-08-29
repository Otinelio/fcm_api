<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Notification;
use App\Models\Restaurant;
use App\Services\Fcm\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class SendGlobalNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_broadcasts_to_clients_and_restaurants_with_device_tokens(): void
    {
        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone' => '+22890000106',
            'password' => bcrypt('secret123'),
        ]);
        $client->deviceTokens()->create(['token' => 'tok-client-broadcast', 'platform' => 'android']);

        $restaurant = Restaurant::create([
            'name' => 'Chez Awa',
            'category' => 'Restaurant',
            'email' => 'commerce-broadcast@example.com',
            'password' => bcrypt('password123'),
        ]);
        $restaurant->deviceTokens()->create(['token' => 'tok-restaurant-broadcast', 'platform' => 'android']);

        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldReceive('sendToToken')->twice()->andReturn(true);
        });

        Artisan::call('notifications:send-all', ['title' => 'Annonce', 'body' => 'Nouveau !', '--delay' => 0]);

        $this->assertSame(1, Notification::where('notifiable_type', $client->getMorphClass())
            ->where('notifiable_id', $client->id)->where('type', 'admin_broadcast')->count());
        $this->assertSame(1, Notification::where('notifiable_type', $restaurant->getMorphClass())
            ->where('notifiable_id', $restaurant->id)->where('type', 'admin_broadcast')->count());
    }
}
