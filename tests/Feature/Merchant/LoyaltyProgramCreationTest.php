<?php

namespace Tests\Feature\Merchant;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `POST /loyalty-programs` — seuls 3 modes existent désormais : stamps,
 * spend, cashback. "points" n'est plus un mode indépendant.
 */
class LoyaltyProgramCreationTest extends TestCase
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

    private array $baseVisuals = [
        'color_primary'     => '#4F46E5',
        'color_secondary'   => '#3730A3',
        'stamp_design_type' => 'check',
    ];

    public function test_points_is_no_longer_a_valid_mode(): void
    {
        [, $token] = $this->restaurantWithToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-programs', [
                'mode' => 'points',
                'goal' => 10,
                ...$this->baseVisuals,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['mode']);
    }

    public function test_creates_a_cashback_program_without_goal(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-programs', [
                'mode'                         => 'cashback',
                'cashback_percentage'          => 5,
                'cashback_redeem_cap_percent'  => 50,
                ...$this->baseVisuals,
            ]);

        $response->assertCreated();
        $program = $restaurant->fresh()->loyaltyProgram;
        $this->assertSame('cashback', $program->type);
        $this->assertSame(5.0, (float) $program->config['cashback_percentage']);
        $this->assertSame(50, $program->config['cashback_redeem_cap_percent']);
        $this->assertSame(0, $program->tiers()->count());
    }

    public function test_cashback_requires_a_percentage(): void
    {
        [, $token] = $this->restaurantWithToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-programs', [
                'mode' => 'cashback',
                ...$this->baseVisuals,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cashback_percentage']);
    }

    public function test_creates_a_spend_program_with_configurable_rate(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-programs', [
                'mode'            => 'spend',
                'tiers'           => [
                    ['goal' => 500, 'reward_description' => 'Café offert'],
                ],
                'fcfa_per_point'  => 100,
                ...$this->baseVisuals,
            ]);

        $response->assertCreated();
        $program = $restaurant->fresh()->loyaltyProgram;
        $this->assertSame('spend', $program->type);
        $this->assertSame(100, $program->config['fcfa_per_point']);
    }

    public function test_stamps_program_still_works_unchanged(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-programs', [
                'mode'  => 'stamps',
                'tiers' => [
                    ['goal' => 8, 'reward_description' => 'Café offert'],
                ],
                ...$this->baseVisuals,
            ]);

        $response->assertCreated();
        $this->assertSame('stamps', $restaurant->fresh()->loyaltyProgram->type);
    }

    public function test_creates_multi_tier_program(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-programs', [
                'mode'  => 'stamps',
                'tiers' => [
                    ['goal' => 500, 'level_name' => 'Découverte', 'reward_description' => 'Boisson offerte'],
                    ['goal' => 1000, 'level_name' => 'Habitué', 'reward_description' => 'Dessert offert'],
                    ['goal' => 2000, 'level_name' => 'VIP', 'reward_description' => 'Menu offert'],
                ],
                ...$this->baseVisuals,
            ]);

        $response->assertCreated();
        $program = $restaurant->fresh()->loyaltyProgram;
        $this->assertSame(3, $program->tiers()->count());
        $this->assertSame('Découverte', $program->tiers->first()->level_name);
    }

    public function test_tiers_goal_must_be_strictly_increasing(): void
    {
        [, $token] = $this->restaurantWithToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-programs', [
                'mode'  => 'stamps',
                'tiers' => [
                    ['goal' => 1000, 'level_name' => 'A', 'reward_description' => 'X'],
                    ['goal' => 500, 'level_name' => 'B', 'reward_description' => 'Y'],
                ],
                ...$this->baseVisuals,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['tiers']);
    }

    public function test_updating_program_replaces_tiers(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/loyalty-programs', [
            'mode' => 'stamps',
            'tiers' => [['goal' => 10, 'level_name' => null, 'reward_description' => 'Café']],
            ...$this->baseVisuals,
        ])->assertCreated();

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/loyalty-programs', [
            'mode' => 'stamps',
            'tiers' => [['goal' => 20, 'level_name' => null, 'reward_description' => 'Dessert']],
            ...$this->baseVisuals,
        ])->assertCreated();

        $program = $restaurant->fresh()->loyaltyProgram;
        $this->assertSame(1, $program->tiers()->count());
        $this->assertSame(20, $program->tiers->first()->goal);
    }
}
