<?php

namespace Tests\Feature\Merchant;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyProgramTier;
use App\Models\LoyaltyReward;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Cashback avec paliers (nouvelle capacité) — voir `MerchantDashboardController::grantCashback`. */
class CashbackTierTest extends TestCase
{
    use RefreshDatabase;

    private function restaurantWithToken(): array
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('password123'),
        ]);
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        return [$restaurant, $token];
    }

    private function cardFor(Restaurant $restaurant, LoyaltyProgram $program): LoyaltyCard
    {
        $client = Client::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Ada',
            'phone' => '+22890000001', 'password' => bcrypt('secret123'),
        ]);

        return LoyaltyCard::create([
            'client_id' => $client->id, 'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id,
        ]);
    }

    public function test_cashback_without_tiers_never_unlocks_a_reward(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'cashback',
            'config' => ['cashback_percentage' => 10],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 100000])
            ->assertOk();

        $this->assertSame(0, LoyaltyReward::where('loyalty_card_id', $card->id)->count());
    }

    public function test_cashback_multi_tier_unlocks_reward_once_per_tier(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'cashback',
            'config' => ['cashback_percentage' => 10],
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 1000, 'level_name' => 'Bronze', 'reward_description' => 'Café offert',
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 2,
            'goal' => 2000, 'level_name' => 'Or', 'reward_description' => 'Menu offert',
        ]);
        $card = $this->cardFor($restaurant, $program);

        // 100 000 FCFA * 10% = 10 000 FCFA de cashback -> franchit les 2 paliers d'un coup.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 100000])
            ->assertOk();

        $this->assertSame(2, LoyaltyReward::where('loyalty_card_id', $card->id)->count());
        $this->assertSame('Or', $card->fresh()->level['name']);
        $this->assertTrue($card->fresh()->level['is_max_level']);
    }

    /**
     * Contrairement à Tampons/Achats, un SEUL palier cashback configuré
     * attribue quand même un niveau définitif — pas de comportement "cycle
     * répété" pour le cashback (voir `LoyaltyTierService`).
     */
    public function test_cashback_single_tier_assigns_a_level_without_cycling(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'cashback',
            'config' => ['cashback_percentage' => 10],
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 1000, 'level_name' => 'Argent', 'reward_description' => 'Café offert',
        ]);
        $card = $this->cardFor($restaurant, $program);

        // 100 000 FCFA * 10% = 10 000 FCFA -> largement au-dessus du seuil.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 100000])
            ->assertOk();

        $fresh = $card->fresh();
        $this->assertSame('Argent', $fresh->level['name']);
        $this->assertTrue($fresh->level['is_max_level']);
        // Un seul déblocage, pas un par tranche de 1000 FCFA cumulés.
        $this->assertSame(1, LoyaltyReward::where('loyalty_card_id', $card->id)->count());
    }

    /** Un palier sans récompense configurée attribue le niveau, ne crée aucune LoyaltyReward. */
    public function test_cashback_tier_without_reward_still_assigns_level(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'cashback',
            'config' => ['cashback_percentage' => 10],
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 1000, 'level_name' => 'Argent', 'reward_description' => null,
        ]);
        $card = $this->cardFor($restaurant, $program);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 100000])
            ->assertOk();

        $this->assertSame(0, $response->json('rewards_unlocked_count'));
        $this->assertFalse($response->json('reward_unlocked'));
        $this->assertSame(0, LoyaltyReward::where('loyalty_card_id', $card->id)->count());
        $this->assertSame('Argent', $card->fresh()->level['name']);
    }

    /**
     * Base `solde` : le niveau affiché suit le solde disponible en direct
     * (peut redescendre) mais la récompense déjà débloquée reste acquise —
     * jamais reprise, jamais re-débloquée une seconde fois au même palier.
     */
    public function test_cashback_balance_basis_level_follows_balance_but_reward_stays_unlocked(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'cashback',
            'config' => ['cashback_percentage' => 10, 'cashback_tier_basis' => 'balance'],
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 1000, 'level_name' => 'Argent', 'reward_description' => 'Café offert',
        ]);
        $card = $this->cardFor($restaurant, $program);
        $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");

        // 100 000 FCFA * 10% = 10 000 FCFA -> franchit le seuil (1000).
        $auth()->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 100000])->assertOk();
        $this->assertSame('Argent', $card->fresh()->level['name']);
        $this->assertSame(1, LoyaltyReward::where('loyalty_card_id', $card->id)->count());

        // Le client utilise tout son solde : le niveau redescend (plus aucun palier atteint).
        $auth()->postJson("/api/merchant/clients/{$card->id}/redeem-cashback", [
            'amount_fcfa' => 10000, 'redeem_amount_fcfa' => 10000,
        ])->assertOk();
        $this->assertNull($card->fresh()->level['name']);
        // La récompense déjà débloquée n'est jamais reprise.
        $this->assertSame(1, LoyaltyReward::where('loyalty_card_id', $card->id)->count());

        // Il regagne du cashback et repasse au-dessus du seuil : le niveau
        // revient, mais AUCUNE nouvelle récompense n'est créée pour ce palier.
        $auth()->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 100000])->assertOk();
        $this->assertSame('Argent', $card->fresh()->level['name']);
        $this->assertSame(1, LoyaltyReward::where('loyalty_card_id', $card->id)->count());
    }

    /** Base `cumulative` (défaut) : utiliser le cashback ne fait jamais perdre le niveau atteint. */
    public function test_cashback_cumulative_basis_never_loses_level_on_redeem(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'cashback',
            'config' => ['cashback_percentage' => 10, 'cashback_tier_basis' => 'cumulative'],
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 1000, 'level_name' => 'Argent', 'reward_description' => 'Café offert',
        ]);
        $card = $this->cardFor($restaurant, $program);
        $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");

        $auth()->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 100000])->assertOk();
        $auth()->postJson("/api/merchant/clients/{$card->id}/redeem-cashback", [
            'amount_fcfa' => 10000, 'redeem_amount_fcfa' => 10000,
        ])->assertOk();

        $fresh = $card->fresh();
        $this->assertSame('Argent', $fresh->level['name']);
        $this->assertSame(0.0, (float) $fresh->cashback_available_fcfa);
    }
}
