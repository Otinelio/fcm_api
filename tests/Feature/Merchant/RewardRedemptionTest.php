<?php

namespace Tests\Feature\Merchant;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cycle de vie d'une récompense débloquée : recherche par QR, validation,
 * annulation — `MerchantDashboardController::lookupReward/redeemReward/cancelReward`.
 */
class RewardRedemptionTest extends TestCase
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

    public function test_reaching_goal_creates_a_traced_reward(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 1, 'reward_description' => 'Burger offert'],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        $this->assertDatabaseHas('loyalty_rewards', [
            'loyalty_card_id' => $card->id,
            'restaurant_id'   => $restaurant->id,
            'title'           => 'Burger offert',
            'status'          => 'available',
        ]);
    }

    public function test_merchant_can_lookup_and_redeem_a_reward_by_token(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 1, 'reward_description' => 'Burger offert'],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        $reward = LoyaltyReward::first();

        $lookup = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/merchant/rewards/lookup?token={$reward->redeem_token}");
        $lookup->assertOk();
        $lookup->assertJsonPath('reward.title', 'Burger offert');
        $lookup->assertJsonPath('reward.status', 'available');

        $redeem = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/rewards/{$reward->id}/redeem");
        $redeem->assertOk();
        $redeem->assertJsonPath('reward.status', 'used');
        $this->assertSame('used', $reward->fresh()->status);
        $this->assertNotNull($reward->fresh()->used_at);
    }

    public function test_redeeming_an_already_used_reward_is_rejected(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 1],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $reward = LoyaltyReward::first();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/rewards/{$reward->id}/redeem")->assertOk();

        $second = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/rewards/{$reward->id}/redeem");
        $second->assertStatus(422);
    }

    public function test_redeeming_an_expired_reward_is_rejected(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 1, 'reward_validity_days' => 1],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $reward = LoyaltyReward::first();
        $reward->update(['expires_at' => now()->subDay()]);

        $this->assertTrue($reward->fresh()->is_expired);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/rewards/{$reward->id}/redeem");
        $response->assertStatus(422);
        $this->assertSame('available', $reward->fresh()->status);
    }

    public function test_merchant_cannot_act_on_another_restaurants_reward(): void
    {
        [$restaurantA, $tokenA] = $this->restaurantWithToken();
        $restaurantB = Restaurant::create([
            'name' => 'Autre resto', 'category' => 'Restaurant',
            'email' => 'autre@example.com', 'password' => bcrypt('password123'),
        ]);
        $tokenB = $restaurantB->createToken('merchant-app')->plainTextToken;

        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurantA->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 1],
        ]);
        $card = $this->cardFor($restaurantA, $program);
        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $reward = LoyaltyReward::first();

        // Le guard `sanctum` mémorise l'acteur résolu pour la durée du test
        // (pas de conteneur neuf par appel simulé, contrairement à une vraie
        // requête HTTP) : sans ce reset, la bascule de jeton A -> B serait
        // ignorée et le test validerait la mauvaise chose silencieusement.
        $this->app['auth']->forgetGuards();

        $lookup = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->getJson("/api/merchant/rewards/lookup?token={$reward->redeem_token}");
        $lookup->assertStatus(404);

        $redeem = $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->postJson("/api/merchant/rewards/{$reward->id}/redeem");
        $redeem->assertStatus(403);
    }

    public function test_merchant_can_cancel_an_available_reward(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 1],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $reward = LoyaltyReward::first();

        $cancel = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/rewards/{$reward->id}/cancel", ['reason' => 'Erreur de saisie']);
        $cancel->assertOk();
        $this->assertSame('canceled', $reward->fresh()->status);

        $redeem = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/rewards/{$reward->id}/redeem");
        $redeem->assertStatus(422);
    }

    public function test_client_rewards_endpoint_lists_own_rewards_across_restaurants(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 1, 'reward_description' => 'Café offert'],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        $clientToken = $card->client->createToken('client-app')->plainTextToken;

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', "Bearer {$clientToken}")
            ->getJson('/api/rewards');
        $response->assertOk();
        $response->assertJsonCount(1, 'rewards');
        $response->assertJsonPath('rewards.0.title', 'Café offert');
    }
}
