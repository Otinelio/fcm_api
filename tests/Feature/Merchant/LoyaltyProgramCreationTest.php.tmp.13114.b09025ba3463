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
        $this->assertNull($program->config['goal']);
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
                'goal'            => 500,
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
                'mode' => 'stamps',
                'goal' => 8,
                ...$this->baseVisuals,
            ]);

        $response->assertCreated();
        $this->assertSame('stamps', $restaurant->fresh()->loyaltyProgram->type);
    }
}
