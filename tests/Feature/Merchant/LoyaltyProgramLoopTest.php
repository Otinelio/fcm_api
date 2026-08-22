<?php

namespace Tests\Feature\Merchant;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyProgramTier;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Comportement configurable après le dernier palier (`loyalty_programs.loops`)
 * — remplace l'ancienne règle implicite basée sur le nombre de paliers. Voir
 * `MerchantDashboardController::grantStampOrPoints`.
 */
class LoyaltyProgramLoopTest extends TestCase
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

    public function test_mono_tier_cycle_unique_terminates_card_after_reward(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'stamps',
            'loops' => false, 'config' => ['goal' => 1],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $r1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps");
        $r1->assertOk();
        $r1->assertJsonPath('reward_unlocked', true);
        $r1->assertJsonPath('program_completed', true);
        $this->assertNotNull($card->fresh()->completed_at);

        $r2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps");
        $r2->assertStatus(422);
    }

    public function test_multi_tier_loop_resets_and_starts_new_cycle(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'stamps',
            'loops' => true, 'config' => [],
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 2, 'level_name' => 'Bronze', 'reward_description' => 'Café offert',
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 2,
            'goal' => 4, 'level_name' => 'Or', 'reward_description' => 'Menu offert',
        ]);
        $card = $this->cardFor($restaurant, $program);

        for ($i = 0; $i < 4; $i++) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        }

        // Cycle 1 complet -> reset immédiat, nouveau cycle démarré à 0.
        $this->assertNull($card->fresh()->completed_at);
        $this->assertSame(0, $card->fresh()->progress['stamps_current']);
        $this->assertSame(2, \App\Models\LoyaltyReward::where('loyalty_card_id', $card->id)->count());

        // Niveau ACTUEL du nouveau cycle : reparti à zéro.
        $this->assertNull($card->fresh()->level['name']);

        // Niveau MAXIMUM historique : conserve "Or", ne redescend pas.
        $this->assertSame('Or', $card->fresh()->max_level_name);
        $this->assertNotNull($card->fresh()->max_level_reached_at);

        // Un 5e tampon progresse bien dans le NOUVEAU cycle (pas plafonné).
        $r5 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps");
        $r5->assertOk();
        $this->assertSame(1, $card->fresh()->progress['stamps_current']);
    }

    public function test_reward_from_final_tier_survives_cycle_reset(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'stamps',
            'loops' => true, 'config' => ['goal' => 2],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        $this->assertSame(0, $card->fresh()->progress['stamps_current']);
        $reward = \App\Models\LoyaltyReward::where('loyalty_card_id', $card->id)->first();
        $this->assertNotNull($reward);
        $this->assertSame('available', $reward->status);
    }

    public function test_single_grant_can_wrap_multiple_full_cycles_in_loop_mode(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'spend',
            'loops' => true, 'config' => ['fcfa_per_point' => 100],
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 5, 'level_name' => 'Bronze', 'reward_description' => 'Café offert',
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 2,
            'goal' => 10, 'level_name' => 'Or', 'reward_description' => 'Menu offert',
        ]);
        $card = $this->cardFor($restaurant, $program);

        // 2300 FCFA / 100 = 23 points -> 2 cycles complets (20) + 3 dans le 3e.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 2300]);

        $response->assertOk();
        $response->assertJsonPath('rewards_unlocked_count', 4); // 2 cycles x 2 paliers
        $this->assertSame(3, $card->fresh()->progress['stamps_current']);
        $this->assertSame(
            2,
            \DB::table('loyalty_transactions')
                ->where('loyalty_card_id', $card->id)
                ->where('type', 'cycle_completed')
                ->count(),
        );
        $this->assertSame('Or', $card->fresh()->max_level_name);
    }

    public function test_max_level_never_downgrades_after_a_later_smaller_cycle(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'stamps',
            'loops' => true, 'config' => [],
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 2, 'level_name' => 'Bronze', 'reward_description' => 'Café offert',
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 2,
            'goal' => 4, 'level_name' => 'Or', 'reward_description' => 'Menu offert',
        ]);
        $card = $this->cardFor($restaurant, $program);

        // Cycle 1 complet -> max = Or.
        for ($i = 0; $i < 4; $i++) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        }
        $this->assertSame('Or', $card->fresh()->max_level_name);
        $firstReachedAt = $card->fresh()->max_level_reached_at;

        // Nouveau cycle, seulement Bronze atteint cette fois (2 tampons).
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        // Le max historique reste "Or", pas rétrogradé à "Bronze".
        $this->assertSame('Or', $card->fresh()->max_level_name);
        $this->assertEquals($firstReachedAt, $card->fresh()->max_level_reached_at);
    }
}
