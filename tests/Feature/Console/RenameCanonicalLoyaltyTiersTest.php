<?php

namespace Tests\Feature\Console;

use App\Models\LoyaltyProgram;
use App\Models\LoyaltyProgramTier;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenameCanonicalLoyaltyTiersTest extends TestCase
{
    use RefreshDatabase;

    private function programWithTiers(array $tierNames): LoyaltyProgram
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('password123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'stamps', 'config' => [],
        ]);
        foreach ($tierNames as $index => $name) {
            LoyaltyProgramTier::create([
                'loyalty_program_id' => $program->id,
                'order' => $index + 1,
                'goal' => ($index + 1) * 500,
                'level_name' => $name,
                'reward_description' => 'Récompense',
            ]);
        }

        return $program;
    }

    public function test_renames_the_first_five_tiers_to_canonical_names_by_position(): void
    {
        $program = $this->programWithTiers(['Débutant', 'VIP', 'Elite', 'Ambassadeur', 'Légende']);

        $this->artisan('loyalty:rename-canonical-tiers')->assertExitCode(0);

        $names = $program->fresh()->tiers->sortBy('goal')->pluck('level_name')->values()->all();
        $this->assertSame(['Bronze', 'Argent', 'Or', 'Platine', 'Fidèle'], $names);
    }

    public function test_keeps_the_free_name_of_tiers_beyond_position_five(): void
    {
        $program = $this->programWithTiers(['A', 'B', 'C', 'D', 'E', 'Mon Palier Custom']);

        $this->artisan('loyalty:rename-canonical-tiers')->assertExitCode(0);

        $names = $program->fresh()->tiers->sortBy('goal')->pluck('level_name')->values()->all();
        $this->assertSame(['Bronze', 'Argent', 'Or', 'Platine', 'Fidèle', 'Mon Palier Custom'], $names);
    }

    public function test_is_idempotent(): void
    {
        $program = $this->programWithTiers(['Débutant', 'VIP']);

        $this->artisan('loyalty:rename-canonical-tiers')->assertExitCode(0);
        $this->artisan('loyalty:rename-canonical-tiers')->assertExitCode(0);

        $names = $program->fresh()->tiers->sortBy('goal')->pluck('level_name')->values()->all();
        $this->assertSame(['Bronze', 'Argent'], $names);
    }
}
