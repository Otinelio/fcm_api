<?php

namespace Tests\Feature;

use App\Models\LoyaltyProgram;
use App\Models\LoyaltyProgramTier;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyProgramTierTest extends TestCase
{
    use RefreshDatabase;

    public function test_tiers_are_ordered_and_belong_to_program(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('password123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'stamps', 'config' => [],
        ]);

        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 2,
            'goal' => 1000, 'level_name' => 'Argent', 'reward_description' => 'Dessert offert',
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 500, 'level_name' => 'Découverte', 'reward_description' => 'Boisson offerte',
        ]);

        $ordered = $program->fresh()->tiers;
        $this->assertSame(['Découverte', 'Argent'], $ordered->pluck('level_name')->all());
        $this->assertSame([500, 1000], $ordered->pluck('goal')->all());
    }
}
