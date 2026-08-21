<?php

namespace Tests\Unit\Services\Loyalty;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyProgramTier;
use App\Models\Restaurant;
use App\Services\Loyalty\LoyaltyTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoyaltyTierServiceTest extends TestCase
{
    use RefreshDatabase;

    private function cardWithProgram(string $type, array $config = [], int $stampsCurrent = 0): LoyaltyCard
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('password123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => $type, 'config' => $config,
        ]);
        $client = Client::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Ada',
            'phone' => '+22890000001', 'password' => bcrypt('secret123'),
        ]);

        return LoyaltyCard::create([
            'client_id' => $client->id, 'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id, 'progress' => ['stamps_current' => $stampsCurrent],
        ]);
    }

    public function test_icon_for_rank_follows_fixed_sequence(): void
    {
        $service = app(LoyaltyTierService::class);
        $this->assertSame('🥉', $service->iconForRank(1));
        $this->assertSame('🥈', $service->iconForRank(2));
        $this->assertSame('🥇', $service->iconForRank(3));
        $this->assertSame('💎', $service->iconForRank(4));
        $this->assertSame('👑', $service->iconForRank(5));
        $this->assertSame('⭐', $service->iconForRank(6));
        $this->assertSame('⭐', $service->iconForRank(12));
    }

    public function test_tiers_falls_back_to_legacy_config_goal_when_no_rows(): void
    {
        $card = $this->cardWithProgram('stamps', ['goal' => 8, 'reward_description' => 'Café offert']);
        $service = app(LoyaltyTierService::class);

        $tiers = $service->tiers($card->loyaltyProgram);

        $this->assertCount(1, $tiers);
        $this->assertSame(8, $tiers[0]['goal']);
        $this->assertSame('Café offert', $tiers[0]['reward_description']);
        $this->assertNull($tiers[0]['level_name']);
        $this->assertNull($tiers[0]['id']);
    }

    public function test_cashback_has_no_implicit_tier(): void
    {
        $card = $this->cardWithProgram('cashback', ['cashback_percentage' => 5]);
        $service = app(LoyaltyTierService::class);

        $this->assertSame([], $service->tiers($card->loyaltyProgram));
    }

    public function test_resolve_returns_null_level_for_single_tier(): void
    {
        $card = $this->cardWithProgram('stamps', ['goal' => 8]);
        $resolved = app(LoyaltyTierService::class)->resolve($card);

        $this->assertNull($resolved['level_name']);
        $this->assertFalse($resolved['is_max_level']);
        $this->assertSame([], $resolved['tiers']);
    }

    public function test_resolve_progresses_through_multi_tier_levels(): void
    {
        $card = $this->cardWithProgram('stamps', [], stampsCurrent: 700);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $card->loyalty_program_id, 'order' => 1,
            'goal' => 500, 'level_name' => 'Découverte', 'reward_description' => 'Boisson offerte',
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $card->loyalty_program_id, 'order' => 2,
            'goal' => 1000, 'level_name' => 'Habitué', 'reward_description' => 'Dessert offert',
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $card->loyalty_program_id, 'order' => 3,
            'goal' => 2000, 'level_name' => 'VIP', 'reward_description' => 'Menu offert',
        ]);

        $resolved = app(LoyaltyTierService::class)->resolve($card->fresh());

        $this->assertSame('Découverte', $resolved['level_name']);
        $this->assertFalse($resolved['is_max_level']);
        // 700 -> palier 1 atteint (500), en cours vers palier 2 (1000) : (700-500)/(1000-500) = 40%.
        $this->assertSame(40, $resolved['percent_to_next']);
        $this->assertSame('reached', $resolved['tiers'][0]['status']);
        $this->assertSame('current', $resolved['tiers'][1]['status']);
        $this->assertSame('upcoming', $resolved['tiers'][2]['status']);
        $this->assertSame('🥉', $resolved['tiers'][0]['icon']);
    }

    public function test_resolve_caps_at_max_level(): void
    {
        $card = $this->cardWithProgram('stamps', [], stampsCurrent: 5000);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $card->loyalty_program_id, 'order' => 1,
            'goal' => 500, 'level_name' => 'Découverte', 'reward_description' => 'Boisson offerte',
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $card->loyalty_program_id, 'order' => 2,
            'goal' => 1000, 'level_name' => 'VIP', 'reward_description' => 'Menu offert',
        ]);

        $resolved = app(LoyaltyTierService::class)->resolve($card->fresh());

        $this->assertSame('VIP', $resolved['level_name']);
        $this->assertTrue($resolved['is_max_level']);
        $this->assertNull($resolved['percent_to_next']);
    }

    public function test_resolve_before_first_tier_reports_current_status_on_first_tier(): void
    {
        $card = $this->cardWithProgram('stamps', [], stampsCurrent: 200);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $card->loyalty_program_id, 'order' => 1,
            'goal' => 500, 'level_name' => 'Découverte', 'reward_description' => 'Boisson offerte',
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $card->loyalty_program_id, 'order' => 2,
            'goal' => 1000, 'level_name' => 'VIP', 'reward_description' => 'Menu offert',
        ]);

        $resolved = app(LoyaltyTierService::class)->resolve($card->fresh());

        $this->assertNull($resolved['level_name']);
        $this->assertFalse($resolved['is_max_level']);
        // 200 -> aucun palier atteint, en cours vers palier 1 (500) : 200/500 = 40%.
        $this->assertSame(40, $resolved['percent_to_next']);
        $this->assertSame('current', $resolved['tiers'][0]['status']);
    }
}
