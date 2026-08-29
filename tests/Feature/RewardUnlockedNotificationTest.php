<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Notification;
use App\Models\Restaurant;
use App\Services\Fcm\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RewardUnlockedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_is_notified_when_a_stamp_operation_unlocks_a_reward(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa',
            'category' => 'Restaurant',
            'email' => 'commerce-reward@example.com',
            'password' => bcrypt('password123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Programme',
            'type' => 'stamps',
            'config' => ['goal' => 1, 'reward_description' => 'Café offert'],
        ]);

        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone' => '+22890000107',
            'password' => bcrypt('secret123'),
        ]);
        $client->deviceTokens()->create(['token' => 'tok-reward', 'platform' => 'android']);
        $card = LoyaltyCard::create([
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id,
        ]);

        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldReceive('sendToToken')->once()->andReturn(true);
        });

        $merchantToken = $restaurant->createToken('merchant-app')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$merchantToken}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $client->getMorphClass(),
            'notifiable_id' => $client->id,
            'type' => 'reward_unlocked',
            'title' => 'Récompense débloquée 🎁',
            'body' => 'Récompense débloquée : Café offert',
        ]);
        $this->assertSame(1, Notification::where('type', 'reward_unlocked')->count());
    }
}
