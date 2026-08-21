<?php

namespace Tests\Feature\Console;

use App\Models\LoyaltyProgram;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrateLoyaltyProgramTiersTest extends TestCase
{
    use RefreshDatabase;

    private function restaurant(): Restaurant
    {
        return Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('password123'),
        ]);
    }

    public function test_merges_rewards_and_levels_by_index_when_counts_match(): void
    {
        $program = LoyaltyProgram::create([
            'restaurant_id' => $this->restaurant()->id, 'name' => 'P', 'type' => 'stamps',
            'config' => [
                'rewards' => [
                    ['goal' => 500, 'reward_description' => 'Boisson offerte'],
                    ['goal' => 1000, 'reward_description' => 'Dessert offert'],
                ],
                'levels' => [
                    ['name' => 'Découverte', 'threshold' => 0],
                    ['name' => 'Habitué', 'threshold' => 2],
                ],
            ],
        ]);

        $this->artisan('loyalty:migrate-tiers')->assertExitCode(0);

        $tiers = $program->fresh()->tiers;
        $this->assertCount(2, $tiers);
        $this->assertSame('Découverte', $tiers[0]->level_name);
        $this->assertSame(500, $tiers[0]->goal);
        $this->assertSame('Habitué', $tiers[1]->level_name);
    }

    public function test_falls_back_to_generic_level_names_when_counts_differ(): void
    {
        $program = LoyaltyProgram::create([
            'restaurant_id' => $this->restaurant()->id, 'name' => 'P', 'type' => 'stamps',
            'config' => [
                'rewards' => [
                    ['goal' => 5, 'reward_description' => 'Café'],
                    ['goal' => 10, 'reward_description' => 'Dessert'],
                ],
                'levels' => [['name' => 'Bronze', 'threshold' => 0]],
            ],
        ]);

        $this->artisan('loyalty:migrate-tiers')->assertExitCode(0);

        $tiers = $program->fresh()->tiers;
        $this->assertCount(2, $tiers);
        $this->assertSame('Palier 1', $tiers[0]->level_name);
        $this->assertSame('Palier 2', $tiers[1]->level_name);
    }

    public function test_legacy_mono_tier_program_gets_one_row(): void
    {
        $program = LoyaltyProgram::create([
            'restaurant_id' => $this->restaurant()->id, 'name' => 'P', 'type' => 'stamps',
            'config' => ['goal' => 8, 'reward_description' => 'Café offert'],
        ]);

        $this->artisan('loyalty:migrate-tiers')->assertExitCode(0);

        $tiers = $program->fresh()->tiers;
        $this->assertCount(1, $tiers);
        $this->assertSame(8, $tiers[0]->goal);
        $this->assertSame('Palier 1', $tiers[0]->level_name);
    }

    public function test_is_idempotent(): void
    {
        $program = LoyaltyProgram::create([
            'restaurant_id' => $this->restaurant()->id, 'name' => 'P', 'type' => 'stamps',
            'config' => ['goal' => 8, 'reward_description' => 'Café offert'],
        ]);

        $this->artisan('loyalty:migrate-tiers')->assertExitCode(0);
        $this->artisan('loyalty:migrate-tiers')->assertExitCode(0);

        $this->assertSame(1, $program->fresh()->tiers()->count());
    }

    public function test_cashback_without_rewards_or_levels_gets_no_tiers(): void
    {
        $program = LoyaltyProgram::create([
            'restaurant_id' => $this->restaurant()->id, 'name' => 'P', 'type' => 'cashback',
            'config' => ['cashback_percentage' => 5],
        ]);

        $this->artisan('loyalty:migrate-tiers')->assertExitCode(0);

        $this->assertSame(0, $program->fresh()->tiers()->count());
    }
}
