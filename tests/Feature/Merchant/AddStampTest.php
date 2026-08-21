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
 * `POST /merchant/clients/{loyaltyCard}/stamps` — chemin "tampons/points"
 * classique (+1) vs mode "Achat" (`spend`, conversion FCFA -> points), voir
 * `MerchantDashboardController::addStamp`.
 */
class AddStampTest extends TestCase
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

    public function test_stamps_mode_grants_a_flat_one_without_amount(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 10],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps");

        $response->assertOk();
        $response->assertJsonPath('points_earned', 1);
        $this->assertSame(1, $card->fresh()->progress['stamps_current']);
    }

    public function test_spend_mode_requires_amount(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'spend',
            'config'        => ['goal' => 10, 'fcfa_per_point' => 500],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['amount_fcfa']);
    }

    public function test_spend_mode_converts_amount_to_points_at_program_rate(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'spend',
            'config'        => ['goal' => 10, 'fcfa_per_point' => 500],
        ]);
        $card = $this->cardFor($restaurant, $program);

        // 2 500 FCFA / 500 = 5 points, pas +1.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", [
                'amount_fcfa' => 2500,
            ]);

        $response->assertOk();
        $response->assertJsonPath('points_earned', 5);
        $this->assertSame(5, $card->fresh()->progress['stamps_current']);
        $this->assertDatabaseHas('loyalty_transactions', [
            'loyalty_card_id'       => $card->id,
            'value'                 => 5,
            'montant_commande_fcfa' => 2500,
        ]);
    }

    public function test_spend_mode_rejects_amount_below_conversion_rate(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'spend',
            'config'        => ['goal' => 10, 'fcfa_per_point' => 500],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", [
                'amount_fcfa' => 200,
            ]);

        $response->assertStatus(422);
    }

    public function test_inactive_card_is_rejected(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 10],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $card->update(['status' => 'inactive']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps");

        $response->assertStatus(422);
        $this->assertSame(0, $card->fresh()->progress['stamps_current']);
    }

    public function test_reaching_goal_unlocks_reward_and_resets_progress(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 1],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps");

        $response->assertOk();
        $response->assertJsonPath('reward_unlocked', true);
        $response->assertJsonPath('client.status', 'reward_available');
        $response->assertJsonPath('client.reward_available', true);
        $this->assertSame('reward_available', $card->fresh()->status);
        $this->assertSame(0, $card->fresh()->progress['stamps_current']);
    }

    public function test_overflow_past_goal_is_carried_over_not_discarded(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'spend',
            'config'        => ['goal' => 500, 'fcfa_per_point' => 100],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $card->update(['progress' => ['stamps_current' => 480]]);

        // +50 points (5 000 FCFA / 100) -> total 530 -> 1 cycle, reste 30.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", [
                'amount_fcfa' => 5000,
            ]);

        $response->assertOk();
        $response->assertJsonPath('reward_unlocked', true);
        $response->assertJsonPath('rewards_unlocked_count', 1);
        $this->assertSame(30, $card->fresh()->progress['stamps_current']);
        $this->assertDatabaseCount('loyalty_transactions', 2); // stamp + 1 cycle_completed
        $this->assertDatabaseHas('loyalty_transactions', [
            'loyalty_card_id' => $card->id,
            'type'            => 'cycle_completed',
            'value'           => 500,
        ]);
    }

    public function test_single_grant_can_unlock_multiple_rewards_at_once(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'spend',
            'config'        => ['goal' => 500, 'fcfa_per_point' => 100],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $card->update(['progress' => ['stamps_current' => 480]]);

        // +1050 points (105 000 FCFA / 100) -> total 1530 -> 3 cycles, reste 30.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", [
                'amount_fcfa' => 105000,
            ]);

        $response->assertOk();
        $response->assertJsonPath('rewards_unlocked_count', 3);
        $this->assertSame(30, $card->fresh()->progress['stamps_current']);
        $this->assertSame(
            3,
            \DB::table('loyalty_transactions')
                ->where('loyalty_card_id', $card->id)
                ->where('type', 'cycle_completed')
                ->count(),
        );
    }

    public function test_lifetime_cycle_count_survives_a_later_reset(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 1],
        ]);
        $card = $this->cardFor($restaurant, $program);

        // Deux cycles complétés l'un après l'autre (goal=1 : chaque tampon
        // déclenche un cycle).
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        $this->assertSame(
            2,
            \DB::table('loyalty_transactions')
                ->where('loyalty_card_id', $card->id)
                ->where('type', 'cycle_completed')
                ->count(),
        );
        // Le compteur de cycle courant, lui, est bien retombé à 0 à chaque fois.
        $this->assertSame(0, $card->fresh()->progress['stamps_current']);
    }

    public function test_response_exposes_goal_percent_and_level(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 5],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $card->update(['progress' => ['stamps_current' => 2]]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps");

        $response->assertOk();
        // 2 + 1 = 3 sur 5 -> 60%.
        $this->assertSame(5, $card->fresh()->goal);
        $this->assertSame(60, $card->fresh()->percent);
        // Un seul palier configuré : pas de système de niveau affiché.
        $this->assertNull($card->fresh()->level);
    }

    public function test_level_progresses_through_multi_tier_program(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => [],
        ]);
        \App\Models\LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 2, 'level_name' => 'Bronze', 'reward_description' => 'Café offert',
        ]);
        \App\Models\LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 2,
            'goal' => 4, 'level_name' => 'Or', 'reward_description' => 'Menu offert',
        ]);
        $card = $this->cardFor($restaurant, $program);

        // Avant le premier palier : pas encore de niveau.
        $this->assertNull($card->fresh()->level['name']);

        // 2 tampons -> palier Bronze atteint pile.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        $level = $card->fresh()->level;
        $this->assertSame('Bronze', $level['name']);
        $this->assertSame(0, $level['percent_to_next']);
        $this->assertFalse($level['is_max_level']);

        // 2 tampons de plus -> Or, niveau max, plafonné (pas de second cycle).
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        $level = $card->fresh()->level;
        $this->assertSame('Or', $level['name']);
        $this->assertTrue($level['is_max_level']);
    }
}
