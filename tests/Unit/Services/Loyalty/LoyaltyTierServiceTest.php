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

    public function test_icon_for_rank_follows_fixed_sequence_when_five_tiers(): void
    {
        $service = app(LoyaltyTierService::class);
        $this->assertSame('🥉', $service->iconForRank(1, 5));
        $this->assertSame('🥈', $service->iconForRank(2, 5));
        $this->assertSame('🥇', $service->iconForRank(3, 5));
        $this->assertSame('💎', $service->iconForRank(4, 5));
        $this->assertSame('👑', $service->iconForRank(5, 5));
    }

    public function test_icon_for_rank_last_tier_is_always_max_regardless_of_total(): void
    {
        $service = app(LoyaltyTierService::class);

        // 2 paliers : le dernier passe directement à l'icône maximale.
        $this->assertSame('🥉', $service->iconForRank(1, 2));
        $this->assertSame('👑', $service->iconForRank(2, 2));

        // 3 paliers : réparti sur toute la plage, dernier toujours 👑.
        $this->assertSame('🥉', $service->iconForRank(1, 3));
        $this->assertSame('🥇', $service->iconForRank(2, 3));
        $this->assertSame('👑', $service->iconForRank(3, 3));

        // Plus de 5 paliers : toujours borné à 👑 au dernier.
        $this->assertSame('🥉', $service->iconForRank(1, 8));
        $this->assertSame('👑', $service->iconForRank(8, 8));
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

    public function test_next_reward_shows_the_real_reward_for_a_mono_tier_program(): void
    {
        $card = $this->cardWithProgram('stamps', ['goal' => 8, 'reward_description' => 'Café offert']);

        $nextReward = app(LoyaltyTierService::class)->nextReward($card);

        $this->assertSame('Café offert', $nextReward['reward_description']);
        $this->assertSame(8, $nextReward['goal']);
        $this->assertSame('🎁', $nextReward['icon']);
    }

    public function test_next_reward_targets_the_first_unreached_tier_for_a_multi_tier_program(): void
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

        $nextReward = app(LoyaltyTierService::class)->nextReward($card->fresh());

        $this->assertSame('Dessert offert', $nextReward['reward_description']);
        $this->assertSame(1000, $nextReward['goal']);
        $this->assertSame('👑', $nextReward['icon']);
    }

    public function test_next_reward_shows_the_last_tier_once_everything_is_reached(): void
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

        $nextReward = app(LoyaltyTierService::class)->nextReward($card->fresh());

        $this->assertSame('Menu offert', $nextReward['reward_description']);
        $this->assertSame('👑', $nextReward['icon']);
    }

    public function test_next_reward_is_null_for_cashback_without_configured_tiers(): void
    {
        $card = $this->cardWithProgram('cashback', ['cashback_percentage' => 5]);

        $nextReward = app(LoyaltyTierService::class)->nextReward($card);

        $this->assertNull($nextReward);
    }

    public function test_next_reward_hides_the_description_when_this_tier_is_not_revealed(): void
    {
        $card = $this->cardWithProgram('stamps', [], stampsCurrent: 1);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $card->loyalty_program_id, 'order' => 1,
            'goal' => 8, 'reward_description' => 'Café offert', 'reveal_reward' => false,
        ]);

        $nextReward = app(LoyaltyTierService::class)->nextReward($card->fresh());

        $this->assertSame('', $nextReward['reward_description']);
        $this->assertSame(8, $nextReward['goal']);
    }

    public function test_resolve_hides_only_the_tiers_the_merchant_marked_as_surprise(): void
    {
        $card = $this->cardWithProgram('stamps', [], stampsCurrent: 700);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $card->loyalty_program_id, 'order' => 1,
            'goal' => 500, 'level_name' => 'Découverte', 'reward_description' => 'Boisson offerte',
        ]);
        // Palier "surprise" : marchand a explicitement masqué celui-ci, pas les autres.
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $card->loyalty_program_id, 'order' => 2,
            'goal' => 1000, 'level_name' => 'Habitué', 'reward_description' => 'Dessert offert',
            'reveal_reward' => false,
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $card->loyalty_program_id, 'order' => 3,
            'goal' => 2000, 'level_name' => 'VIP', 'reward_description' => 'Menu offert',
        ]);

        $resolved = app(LoyaltyTierService::class)->resolve($card->fresh());

        // Palier 1 (700 >= 500) : atteint, déjà débloqué -> jamais masqué.
        $this->assertSame('reached', $resolved['tiers'][0]['status']);
        $this->assertSame('Boisson offerte', $resolved['tiers'][0]['reward_description']);
        // Palier 2 (current) : marqué "surprise" par le marchand -> masqué.
        $this->assertSame('current', $resolved['tiers'][1]['status']);
        $this->assertSame('', $resolved['tiers'][1]['reward_description']);
        // Palier 3 (upcoming) : pas marqué "surprise" -> reste visible.
        $this->assertSame('upcoming', $resolved['tiers'][2]['status']);
        $this->assertSame('Menu offert', $resolved['tiers'][2]['reward_description']);
    }

    /**
     * Bug repro : après un reset de cycle (`loops=true`), un palier déjà
     * débloqué (une vraie `LoyaltyReward` existe) ne doit jamais redevenir
     * "current"/"upcoming" ni se refaire masquer — la progression du
     * nouveau cycle repart à zéro, pas l'historique des récompenses déjà
     * accordées (voir `MerchantDashboardController::grantStampOrPoints`).
     */
    public function test_resolve_keeps_a_tier_reached_after_its_cycle_resets(): void
    {
        $card = $this->cardWithProgram('stamps', [], stampsCurrent: 0);
        $card->loyaltyProgram->update(['loops' => true]);
        $tier1 = LoyaltyProgramTier::create([
            'loyalty_program_id' => $card->loyalty_program_id, 'order' => 1,
            'goal' => 10, 'level_name' => 'Niveau 1', 'reward_description' => '1 café offert',
            'reveal_reward' => false,
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $card->loyalty_program_id, 'order' => 2,
            'goal' => 20, 'level_name' => 'Niveau 2', 'reward_description' => 'Menu offert',
        ]);
        // Récompense déjà accordée pour le palier 1 lors du cycle précédent.
        \App\Models\LoyaltyReward::create([
            'loyalty_card_id' => $card->id, 'restaurant_id' => $card->restaurant_id,
            'program_tier_id' => $tier1->id, 'title' => '1 café offert', 'unlocked_at' => now(),
        ]);

        // Le cycle a wrappé : la progression repart de 0, comme après un reset.
        $resolved = app(LoyaltyTierService::class)->resolve($card->fresh());

        $this->assertSame('reached', $resolved['tiers'][0]['status']);
        $this->assertSame('1 café offert', $resolved['tiers'][0]['reward_description']);
    }

    public function test_resolve_keeps_reward_descriptions_visible_by_default(): void
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

        $this->assertSame('Boisson offerte', $resolved['tiers'][0]['reward_description']);
    }
}
