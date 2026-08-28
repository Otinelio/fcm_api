<?php

namespace Tests\Feature\Merchant;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyProgramTier;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /merchant/clients — filtres structurés (`inactive_days`, `level`,
 * `min_cycles`), tri (`sort`) et pagination (`page`/`per_page`).
 *
 * Remplace l'ancien `filter=inactive_30d` (durée codée en dur) : le contrat
 * est cassé volontairement, l'app Flutter et l'API se déploient ensemble.
 */
class ClientFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function restaurantWithToken(): array
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa',
            'category' => 'Restaurant',
            'email' => 'commerce@example.com',
            'password' => bcrypt('password123'),
        ]);
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        return [$restaurant, $token];
    }

    private function cardFor(
        Restaurant $restaurant,
        LoyaltyProgram $program,
        string $firstName = 'Ada',
        ?string $phone = null,
        array $overrides = [],
    ): LoyaltyCard {
        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => $firstName,
            'phone' => $phone ?? '+22890000'.random_int(1000, 9999),
            'password' => bcrypt('secret123'),
        ]);

        return LoyaltyCard::create(array_merge([
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id,
            'progress' => ['stamps_current' => 0],
        ], $overrides));
    }

    public function test_list_uses_paginated_envelope_with_meta(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        for ($i = 0; $i < 3; $i++) {
            $this->cardFor($restaurant, $program);
        }

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients?per_page=2&page=1');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.last_page', 2);

        $pageTwo = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients?per_page=2&page=2');
        $pageTwo->assertOk();
        $pageTwo->assertJsonCount(1, 'data');
    }

    public function test_inactive_days_filter_accepts_a_custom_duration(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);

        $recent = $this->cardFor($restaurant, $program, firstName: 'Recent');
        $stale = $this->cardFor($restaurant, $program, firstName: 'Stale');
        $never = $this->cardFor($restaurant, $program, firstName: 'Never');

        $recent->update(['last_activity_at' => now()->subDays(5)]);
        $stale->update(['last_activity_at' => now()->subDays(10)]);

        // 7 jours : Stale (10j) et Never (jamais actif) sortent, pas Recent.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients?inactive_days=7');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('client.first_name')->all();
        $this->assertEqualsCanonicalizing(['Stale', 'Never'], $names);
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_level_filter_matches_canonical_keys(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        // Programme sans boucle (`loops` vaut true par défaut en base) : la
        // progression est cumulative jusqu'au dernier palier, le niveau
        // atteint ne retombe jamais à zéro.
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'loops' => false, 'config' => [],
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 2, 'level_name' => 'Bronze', 'reward_description' => 'Café offert',
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 2,
            'goal' => 4, 'level_name' => 'Or', 'reward_description' => 'Menu offert',
        ]);

        $bronzeOnly = $this->cardFor($restaurant, $program, firstName: 'Bronze');
        $goldClient = $this->cardFor($restaurant, $program, firstName: 'Goldie');

        // BronzeOnly : palier 1 franchi uniquement ; Goldie : les deux.
        for ($i = 0; $i < 3; $i++) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson("/api/merchant/clients/{$bronzeOnly->id}/stamps")->assertOk();
        }
        for ($i = 0; $i < 4; $i++) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson("/api/merchant/clients/{$goldClient->id}/stamps")->assertOk();
        }

        // La clé canonique est exposée dans la réponse de liste.
        $list = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients');
        $byName = collect($list->json('data'))->pluck('level.key', 'client.first_name')->all();
        $this->assertSame('bronze', $byName['Bronze']); // dérivé du libellé « Bronze »
        $this->assertSame('gold', $byName['Goldie']);

        $goldOnly = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients?level=gold');
        $this->assertSame(['Goldie'], collect($goldOnly->json('data'))->pluck('client.first_name')->all());
    }

    /**
     * Régression : un client tout juste ajouté (0 progression) sur un
     * programme multi-palier affichait le niveau "Fidèle" (dernier palier)
     * au lieu de "Bronze" (premier) — `levelKey(null)` retombait sur son
     * fallback `'custom'`. Voir `LoyaltyCard::getLevelAttribute`.
     */
    public function test_brand_new_client_shows_bronze_not_last_tier(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'loops' => false, 'config' => [],
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 3, 'level_name' => 'Bronze', 'reward_description' => 'A',
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 2,
            'goal' => 9, 'level_name' => 'Fidèle', 'reward_description' => 'B',
        ]);
        $card = $this->cardFor($restaurant, $program, firstName: 'Nouveau');

        $this->assertNull($card->level['name']);
        $this->assertSame('bronze', $card->level['key']);
        $this->assertSame(1, $card->level['position']);
        $this->assertFalse($card->level['is_max_level']);

        // Le filtre marchand par niveau doit le classer sous "bronze", pas
        // sous "custom" (clé du dernier palier "Fidèle" ici).
        $bronzeFilter = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients?level=bronze');
        $this->assertSame(['Nouveau'], collect($bronzeFilter->json('data'))->pluck('client.first_name')->all());

        $customFilter = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients?level=custom');
        $this->assertSame([], collect($customFilter->json('data'))->pluck('client.first_name')->all());
    }

    public function test_min_cycles_filters_clients_by_completed_cycles(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'loops' => true, 'config' => [],
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 2, 'level_name' => 'Bronze', 'reward_description' => 'Café offert',
        ]);

        $starter = $this->cardFor($restaurant, $program, firstName: 'Starter');
        $veteran = $this->cardFor($restaurant, $program, firstName: 'Veteran');

        // Starter : 1 cycle complet. Veteran : 3 cycles complets.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$starter->id}/stamps")->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$starter->id}/stamps")->assertOk();

        for ($i = 0; $i < 6; $i++) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson("/api/merchant/clients/{$veteran->id}/stamps")->assertOk();
        }

        $this->assertSame(1, $starter->fresh()->cycles_completed);
        $this->assertSame(3, $veteran->fresh()->cycles_completed);

        $filtered = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients?min_cycles=2');
        $this->assertSame(['Veteran'], collect($filtered->json('data'))->pluck('client.first_name')->all());

        $anyCompleted = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients?min_cycles=1');
        $this->assertSame(2, $anyCompleted->json('meta.total'));
    }

    public function test_removing_a_stamp_decrements_cycles_completed(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'loops' => true, 'config' => [],
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 2, 'level_name' => 'Bronze', 'reward_description' => 'Café offert',
        ]);
        $card = $this->cardFor($restaurant, $program);
        $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");

        $auth()->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $auth()->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->assertSame(1, $card->fresh()->cycles_completed);
        $this->assertSame(1, DB::table('loyalty_transactions')
            ->where('loyalty_card_id', $card->id)
            ->where('type', 'cycle_completed')->where('status', 'valid')->count());

        // Le retrait restaure la progression au niveau d'avant CE gain
        // (le premier tampon, hors cycle, reste en place) et annule le
        // cycle journalisé.
        $auth()->deleteJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        $this->assertSame(0, $card->fresh()->cycles_completed);
        $this->assertSame(0, DB::table('loyalty_transactions')
            ->where('loyalty_card_id', $card->id)
            ->where('type', 'cycle_completed')->where('status', 'valid')->count());
        $this->assertSame(1, $card->fresh()->progress['stamps_current']);
    }

    public function test_sort_orders_clients_by_requested_field(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);

        $old = $this->cardFor($restaurant, $program, firstName: 'Old');
        sleep(0);
        $new = $this->cardFor($restaurant, $program, firstName: 'New');

        // Forcer des valeurs distinctes et contrôlées pour chaque tri.
        LoyaltyCard::query()->whereKey($old->id)->update([
            'created_at' => now()->subDays(10),
            'last_activity_at' => now()->subHour(), // Old : actif récemment
        ]);
        LoyaltyCard::query()->whereKey($new->id)->update([
            'created_at' => now(),
            'last_activity_at' => now()->subDays(5), // New : inactif depuis 5j
        ]);

        $activity = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients?sort=activity');
        $activity->assertOk();
        $this->assertSame(['Old', 'New'], collect($activity->json('data'))->pluck('client.first_name')->all());

        $recent = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients?sort=recent');
        $recent->assertOk();
        $this->assertSame(['New', 'Old'], collect($recent->json('data'))->pluck('client.first_name')->all());

        $oldest = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients?sort=oldest');
        $oldest->assertOk();
        $this->assertSame(['Old', 'New'], collect($oldest->json('data'))->pluck('client.first_name')->all());
    }

    /** Segmentation marchand par cashback cumulé à vie (spec §7). */
    public function test_min_lifetime_cashback_filters_clients_by_cumulative_cashback(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'cashback',
            'config' => ['cashback_percentage' => 10],
        ]);
        $small = $this->cardFor($restaurant, $program, firstName: 'Small');
        $big = $this->cardFor($restaurant, $program, firstName: 'Big');
        $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");

        $auth()->postJson("/api/merchant/clients/{$small->id}/stamps", ['amount_fcfa' => 10000])->assertOk(); // 1 000 FCFA
        $auth()->postJson("/api/merchant/clients/{$big->id}/stamps", ['amount_fcfa' => 100000])->assertOk(); // 10 000 FCFA

        $filtered = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients?min_lifetime_cashback=5000');
        $this->assertSame(['Big'], collect($filtered->json('data'))->pluck('client.first_name')->all());
    }

    public function test_per_page_is_capped_and_invalid_values_fall_back(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        for ($i = 0; $i < 4; $i++) {
            $this->cardFor($restaurant, $program);
        }

        // per_page > 100 serait plafonné — ici 4 clients, on vérifie juste
        // qu'une valeur absurde ne casse pas la requête.
        $capped = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients?per_page=-5');
        $capped->assertOk();
        $this->assertSame(25, $capped->json('meta.per_page'));

        $huge = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients?per_page=5000');
        $huge->assertOk();
        $this->assertSame(100, $huge->json('meta.per_page'));
        $this->assertSame(4, $huge->json('meta.total'));
    }
}
