<?php

namespace Tests\Feature\Merchant;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mode Cashback : accumulation au pourcentage, rachat plafonné — voir
 * `MerchantDashboardController::grantCashback/redeemCashback`.
 */
class CashbackTest extends TestCase
{
    use RefreshDatabase;

    private function restaurantWithToken(): array
    {
        $restaurant = Restaurant::create([
            'name'     => 'Chez Awa',
            'category' => 'Restaurant',
            'email'    => 'commerce@example.com',
            'password' => bcrypt('password123'),
        ]);
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        return [$restaurant, $token];
    }

    private function cardFor(Restaurant $restaurant, LoyaltyProgram $program): LoyaltyCard
    {
        $client = Client::create([
            'uuid'       => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone'      => '+22890000001',
            'password'   => bcrypt('secret123'),
        ]);

        return LoyaltyCard::create([
            'client_id'          => $client->id,
            'restaurant_id'      => $restaurant->id,
            'loyalty_program_id' => $program->id,
            'progress'           => ['stamps_current' => 0],
        ]);
    }

    public function test_purchase_credits_the_configured_percentage(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'cashback',
            'config'        => ['cashback_percentage' => 5],
        ]);
        $card = $this->cardFor($restaurant, $program);

        // 5% de 20 000 FCFA = 1 000 FCFA.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 20000]);

        $response->assertOk();
        $response->assertJsonPath('cashback_earned', 1000);
        $this->assertSame('1000.00', $card->fresh()->cashback_balance_fcfa);
        $this->assertDatabaseHas('loyalty_transactions', [
            'loyalty_card_id'       => $card->id,
            'type'                  => 'cashback_earn',
            'value'                 => 1000,
            'montant_commande_fcfa' => 20000,
        ]);
        // Pas de cycle pour le cashback : aucun statut "reward_available".
        $this->assertSame('active', $card->fresh()->status);
    }

    public function test_balance_accumulates_across_purchases(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'cashback',
            'config'        => ['cashback_percentage' => 5],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $card->update(['cashback_balance_fcfa' => 2000]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 20000])
            ->assertOk();

        $this->assertSame('3000.00', $card->fresh()->cashback_balance_fcfa);
    }

    public function test_redeem_deducts_balance_within_purchase_and_cap(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'cashback',
            'config'        => ['cashback_percentage' => 5, 'cashback_redeem_cap_percent' => 50],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $card->update(['cashback_balance_fcfa' => 5000]);

        // Achat 10 000 FCFA, plafond 50% -> max utilisable 5 000 FCFA.
        // Le client choisit d'utiliser 2 000 FCFA seulement.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/redeem-cashback", [
                'amount_fcfa'        => 10000,
                'redeem_amount_fcfa' => 2000,
            ]);

        $response->assertOk();
        $this->assertSame('3000.00', $card->fresh()->cashback_balance_fcfa);
        $this->assertDatabaseHas('loyalty_transactions', [
            'loyalty_card_id'       => $card->id,
            'type'                  => 'cashback_redeem',
            'value'                 => 2000,
            'montant_commande_fcfa' => 10000,
        ]);
    }

    public function test_redeem_rejects_amount_above_purchase_cap(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'cashback',
            'config'        => ['cashback_percentage' => 5, 'cashback_redeem_cap_percent' => 50],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $card->update(['cashback_balance_fcfa' => 10000]);

        // Achat 10 000 FCFA, plafond 50% -> max 5 000 ; le client tente 6 000.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/redeem-cashback", [
                'amount_fcfa'        => 10000,
                'redeem_amount_fcfa' => 6000,
            ]);

        $response->assertStatus(422);
        $this->assertSame('10000.00', $card->fresh()->cashback_balance_fcfa);
    }

    public function test_redeem_rejects_amount_above_balance(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'cashback',
            'config'        => ['cashback_percentage' => 5],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $card->update(['cashback_balance_fcfa' => 1000]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/redeem-cashback", [
                'amount_fcfa'        => 10000,
                'redeem_amount_fcfa' => 1500,
            ]);

        $response->assertStatus(422);
        $this->assertSame('1000.00', $card->fresh()->cashback_balance_fcfa);
    }

    public function test_redeem_without_configured_cap_is_only_limited_by_balance(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'cashback',
            'config'        => ['cashback_percentage' => 5],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $card->update(['cashback_balance_fcfa' => 9000]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/redeem-cashback", [
                'amount_fcfa'        => 10000,
                'redeem_amount_fcfa' => 9000,
            ]);

        $response->assertOk();
        $this->assertSame('0.00', $card->fresh()->cashback_balance_fcfa);
    }
}
