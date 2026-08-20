<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GET /loyalty-cards/{card}/history` — remplace l'historique fabriqué
 * côté Flutter par les vraies lignes `loyalty_transactions`.
 */
class LoyaltyCardHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function restaurantWithToken(): array
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('password123'),
        ]);
        return [$restaurant, $restaurant->createToken('merchant-app')->plainTextToken];
    }

    private function clientWithToken(): array
    {
        $client = Client::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Ada',
            'phone' => '+228' . random_int(10000000, 99999999), 'password' => bcrypt('secret123'),
        ]);
        return [$client, $client->createToken('client-app')->plainTextToken];
    }

    public function test_history_lists_real_operations_in_reverse_chronological_order(): void
    {
        [$restaurant, $merchantToken] = $this->restaurantWithToken();
        [$client, $clientToken] = $this->clientWithToken();

        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme',
            'type' => 'stamps', 'config' => ['goal' => 100],
        ]);
        $card = LoyaltyCard::create([
            'client_id' => $client->id, 'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id, 'progress' => ['stamps_current' => 0],
        ]);

        $this->withHeader('Authorization', "Bearer {$merchantToken}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->withHeader('Authorization', "Bearer {$merchantToken}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', "Bearer {$clientToken}")
            ->getJson("/api/loyalty-cards/{$card->id}/history");

        $response->assertOk();
        $response->assertJsonCount(2, 'history');
        $response->assertJsonPath('history.0.type', 'stamp');
        $response->assertJsonPath('history.0.value', 1);
        // cycle_completed n'est jamais un pas un événement "action du client".
        $this->assertDatabaseMissing('loyalty_transactions', ['type' => 'cycle_completed']);
    }

    public function test_history_includes_cashback_earn_and_redeem(): void
    {
        [$restaurant, $merchantToken] = $this->restaurantWithToken();
        [$client, $clientToken] = $this->clientWithToken();

        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme',
            'type' => 'cashback', 'config' => ['cashback_percentage' => 5],
        ]);
        $card = LoyaltyCard::create([
            'client_id' => $client->id, 'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id, 'progress' => ['stamps_current' => 0],
            'cashback_balance_fcfa' => 1000,
        ]);

        $this->withHeader('Authorization', "Bearer {$merchantToken}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 10000])
            ->assertOk();
        $this->withHeader('Authorization', "Bearer {$merchantToken}")
            ->postJson("/api/merchant/clients/{$card->id}/redeem-cashback", [
                'amount_fcfa' => 5000, 'redeem_amount_fcfa' => 500,
            ])->assertOk();

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', "Bearer {$clientToken}")
            ->getJson("/api/loyalty-cards/{$card->id}/history");

        $response->assertOk();
        $response->assertJsonCount(2, 'history');
        $response->assertJsonPath('history.0.type', 'cashback_redeem');
        $response->assertJsonPath('history.0.value', 500);
        $response->assertJsonPath('history.1.type', 'cashback_earn');
        $response->assertJsonPath('history.1.value', 500);
    }

    public function test_history_rejects_another_clients_card(): void
    {
        [$restaurant, $merchantToken] = $this->restaurantWithToken();
        [$client] = $this->clientWithToken();
        [, $otherClientToken] = $this->clientWithToken();

        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme',
            'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        $card = LoyaltyCard::create([
            'client_id' => $client->id, 'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id, 'progress' => ['stamps_current' => 0],
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$otherClientToken}")
            ->getJson("/api/loyalty-cards/{$card->id}/history");

        $response->assertStatus(403);
    }
}
