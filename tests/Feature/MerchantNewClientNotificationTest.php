<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Notification;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantNewClientNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurant_gets_an_in_app_notification_when_a_new_card_is_created(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa',
            'category' => 'Restaurant',
            'email' => 'commerce-newclient@example.com',
            'password' => bcrypt('password123'),
        ]);
        LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Programme',
            'type' => 'stamps',
            'config' => ['goal' => 10],
        ]);

        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone' => '+22890000108',
            'password' => bcrypt('secret123'),
        ]);
        $token = $client->createToken('mobile-app')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-cards/join', ['qr_token' => $restaurant->qr_token])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $restaurant->getMorphClass(),
            'notifiable_id' => $restaurant->id,
            'type' => 'merchant_new_client',
        ]);
    }

    public function test_no_notification_when_the_client_was_already_a_member(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa',
            'category' => 'Restaurant',
            'email' => 'commerce-newclient2@example.com',
            'password' => bcrypt('password123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Programme',
            'type' => 'stamps',
            'config' => ['goal' => 10],
        ]);

        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone' => '+22890000109',
            'password' => bcrypt('secret123'),
        ]);
        LoyaltyCard::create([
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id,
        ]);
        $token = $client->createToken('mobile-app')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-cards/join', ['qr_token' => $restaurant->qr_token])
            ->assertCreated();

        $this->assertSame(0, Notification::where('type', 'merchant_new_client')->count());
    }
}
