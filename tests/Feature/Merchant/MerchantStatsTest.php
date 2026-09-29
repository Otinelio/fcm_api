<?php

namespace Tests\Feature\Merchant;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_stats_returns_accurate_today_month_and_weekly_activity(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa',
            'category' => 'Restaurant',
            'email' => 'commerce@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Programme Fidélité',
            'type' => 'stamps',
            'is_active' => true,
        ]);

        $client = Client::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'first_name' => 'Kofi',
            'last_name' => 'A.',
            'phone' => '+22890000001',
        ]);

        $card = LoyaltyCard::create([
            'restaurant_id' => $restaurant->id,
            'client_id' => $client->id,
            'loyalty_program_id' => $program->id,
            'status' => 'active',
            'points' => 5,
        ]);

        // Transaction aujourd'hui
        LoyaltyTransaction::create([
            'loyalty_card_id' => $card->id,
            'type' => 'stamp',
            'value' => 1,
            'status' => 'valid',
            'created_at' => now(),
        ]);

        // Transaction semaine passée du mois en cours
        LoyaltyTransaction::create([
            'loyalty_card_id' => $card->id,
            'type' => 'stamp',
            'value' => 1,
            'status' => 'valid',
            'created_at' => now()->startOfMonth()->addDays(2),
        ]);

        $token = $restaurant->createToken('merchant-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/stats');

        $response->assertOk();
        $response->assertJsonStructure([
            'total_clients',
            'stamps_today',
            'stamps_this_month',
            'weekly_activity',
            'active_rewards',
            'recent_activity',
        ]);

        $this->assertIsArray($response->json('weekly_activity'));
        $this->assertCount(4, $response->json('weekly_activity'));
        $this->assertGreaterThanOrEqual(1, $response->json('stamps_today'));
        $this->assertGreaterThanOrEqual(2, $response->json('stamps_this_month'));
    }
}
