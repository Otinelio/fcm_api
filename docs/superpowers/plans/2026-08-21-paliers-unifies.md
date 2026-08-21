# Paliers unifiés (objectif + niveau + récompense) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fusionner les systèmes actuellement indépendants "récompenses en cycle" et "niveaux à vie" en un seul concept de palier (objectif + niveau + récompense), pour tous les types de programme, en cassant le moins de code/tests existants possible.

**Architecture:** Nouvelle table `loyalty_program_tiers` remplace les JSON `config['rewards']`/`config['levels']`. Nouveau `LoyaltyTierService` fusionne `RewardTierService`+`LoyaltyLevelService`. **Un seul palier configuré = comportement cycle répété actuel, strictement inchangé, jamais de niveau affiché.** **Deux paliers ou plus = nouveau mode cumulatif à vie, plafonné au dernier palier**, avec niveau nommé par palier. Cette distinction (mono vs multi) est la clé qui permet de ne toucher aucun programme existant (tous mono-palier aujourd'hui) tout en offrant le nouveau mode aux marchands qui ajoutent un 2e palier.

**Tech Stack:** Laravel 13 (backend `restaurant-loyalty-api`), Flutter/Riverpod (`Miva_Fid`), PostgreSQL (Reverb/broadcasting inchangés).

**Spec:** `docs/superpowers/specs/2026-08-21-paliers-niveaux-recompenses-design.md`

## Global Constraints

- Icônes de niveau automatiques par rang, jamais choisies par le marchand : `['🥉','🥈','🥇','💎','👑']` indexé 0-4, `'⭐'` au-delà.
- Mono-palier (1 seul palier configuré) : comportement byte-pour-byte identique à l'existant (cycle répété, `progress['stamps_current']` remis à zéro, aucun niveau affiché) — **ne pas toucher** ce chemin de code dans `grantStampOrPoints`.
- Multi-palier (2+) : cumulatif à vie, jamais reset, plafonné au dernier palier atteint (pas de second cycle).
- Ne rien changer d'autre dans l'application (pas de refonte visuelle hors du strict nécessaire pour afficher paliers/niveaux/roadmap).

---

## Backend (`restaurant-loyalty-api`)

### Task 1: Table `loyalty_program_tiers` + colonne de traçabilité sur `loyalty_rewards`

**Files:**
- Create: `database/migrations/2026_08_21_100000_create_loyalty_program_tiers_table.php`
- Create: `database/migrations/2026_08_21_100001_add_program_tier_id_to_loyalty_rewards_table.php`
- Create: `app/Models/LoyaltyProgramTier.php`
- Modify: `app/Models/LoyaltyProgram.php`
- Modify: `app/Models/LoyaltyReward.php`
- Test: `tests/Feature/LoyaltyProgramTierTest.php`

**Interfaces:**
- Produces: `LoyaltyProgram::tiers(): HasMany<LoyaltyProgramTier>` (ordonné par `order`), `LoyaltyProgramTier` fillable `['loyalty_program_id','order','goal','level_name','reward_description','validity_days']`, `LoyaltyReward::programTier(): BelongsTo<LoyaltyProgramTier>`.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LoyaltyProgramTierTest`
Expected: FAIL — table `loyalty_program_tiers` doesn't exist / class not found.

- [ ] **Step 3: Write the migrations and models**

`database/migrations/2026_08_21_100000_create_loyalty_program_tiers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remplace `loyalty_programs.config['rewards']`/`config['levels']` : un
 * palier = un objectif (seuil, cumulatif à vie si le programme en a 2+) + un
 * niveau (nom libre) + une récompense. Un programme à un seul palier garde
 * le comportement "cycle répété" existant (voir `LoyaltyTierService`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_program_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_program_id')->constrained('loyalty_programs')->cascadeOnDelete();
            $table->unsignedSmallInteger('order');
            $table->unsignedInteger('goal');
            $table->string('level_name')->nullable();
            $table->string('reward_description');
            $table->unsignedInteger('validity_days')->nullable();
            $table->timestamps();

            $table->unique(['loyalty_program_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_program_tiers');
    }
};
```

`database/migrations/2026_08_21_100001_add_program_tier_id_to_loyalty_rewards_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->foreignId('program_tier_id')->nullable()
                ->after('loyalty_card_id')
                ->constrained('loyalty_program_tiers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_tier_id');
        });
    }
};
```

`app/Models/LoyaltyProgramTier.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyProgramTier extends Model
{
    protected $fillable = [
        'loyalty_program_id',
        'order',
        'goal',
        'level_name',
        'reward_description',
        'validity_days',
    ];

    public function loyaltyProgram()
    {
        return $this->belongsTo(LoyaltyProgram::class);
    }
}
```

In `app/Models/LoyaltyProgram.php`, add after `restaurant()`:

```php
    public function tiers()
    {
        return $this->hasMany(LoyaltyProgramTier::class)->orderBy('order');
    }
```

In `app/Models/LoyaltyReward.php`, add `'program_tier_id'` to `$fillable` (after `'loyalty_card_id'`) and add after `loyaltyCard()`:

```php
    public function programTier()
    {
        return $this->belongsTo(LoyaltyProgramTier::class);
    }
```

- [ ] **Step 4: Run migrations and test**

Run: `php artisan migrate && php artisan test --filter=LoyaltyProgramTierTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_21_100000_create_loyalty_program_tiers_table.php \
        database/migrations/2026_08_21_100001_add_program_tier_id_to_loyalty_rewards_table.php \
        app/Models/LoyaltyProgramTier.php app/Models/LoyaltyProgram.php app/Models/LoyaltyReward.php \
        tests/Feature/LoyaltyProgramTierTest.php
git commit -m "feat: add loyalty_program_tiers table (objectif+niveau+récompense unifiés)"
```

---

### Task 2: `LoyaltyTierService` (fusion des deux anciens services)

**Files:**
- Create: `app/Services/Loyalty/LoyaltyTierService.php`
- Test: `tests/Unit/Services/Loyalty/LoyaltyTierServiceTest.php`

**Interfaces:**
- Consumes: `LoyaltyProgram::tiers()` (Task 1), `LoyaltyCard::progress` (json, key `stamps_current`), `loyalty_transactions` table (`cashback_earn`, column `value`).
- Produces:
  - `tiers(?LoyaltyProgram $program): array` — chaque élément `['id'=>?int,'order'=>int,'goal'=>int,'level_name'=>?string,'reward_description'=>string,'validity_days'=>?int]`, triés par `goal` croissant.
  - `iconForRank(int $rank): string` — `$rank` 1-based.
  - `lifetimeCashback(LoyaltyCard $card): float` (déplacé tel quel depuis `LoyaltyLevelService`).
  - `resolve(LoyaltyCard $card): array` — `['level_name'=>?string,'percent_to_next'=>?int,'is_max_level'=>bool,'tiers'=>array]`, `tiers` vide si `count(tiers(program)) <= 1`.

- [ ] **Step 1: Write the failing test**

```php
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LoyaltyTierServiceTest`
Expected: FAIL — class `LoyaltyTierService` not found.

- [ ] **Step 3: Write the implementation**

`app/Services/Loyalty/LoyaltyTierService.php`:

```php
<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use Illuminate\Support\Facades\DB;

/**
 * Résout les paliers d'un programme (objectif + niveau + récompense
 * unifiés). Remplace `RewardTierService` et `LoyaltyLevelService`.
 *
 * Distinction volontaire, cœur de la conception (voir spec) :
 * - 1 seul palier configuré : comportement "cycle répété" existant,
 *   `progress['stamps_current']` remis à zéro à chaque déblocage, jamais de
 *   niveau affiché. Géré directement par `MerchantDashboardController`, pas
 *   par ce service (`resolve()` renvoie `tiers: []`, `level_name: null`).
 * - 2 paliers ou plus : cumulatif à vie (jamais reset), plafonné au dernier
 *   palier une fois atteint. C'est ce que `resolve()` calcule.
 */
class LoyaltyTierService
{
    private const ICONS = ['🥉', '🥈', '🥇', '💎', '👑'];
    private const DEFAULT_ICON = '⭐';

    public function iconForRank(int $rank): string
    {
        return self::ICONS[$rank - 1] ?? self::DEFAULT_ICON;
    }

    /**
     * @return array<int, array{id: ?int, order: int, goal: int, level_name: ?string, reward_description: string, validity_days: ?int}>
     * Trié par `goal` croissant.
     */
    public function tiers(?LoyaltyProgram $program): array
    {
        if ($program === null) {
            return [];
        }

        $rows = $program->tiers;
        if ($rows->isNotEmpty()) {
            return $rows
                ->sortBy('goal')
                ->values()
                ->map(fn ($r) => [
                    'id'                  => $r->id,
                    'order'               => $r->order,
                    'goal'                => (int) $r->goal,
                    'level_name'          => $r->level_name,
                    'reward_description'  => $r->reward_description,
                    'validity_days'       => $r->validity_days ?? ($program->config['reward_validity_days'] ?? null),
                ])
                ->all();
        }

        // Programme jamais migré vers la table de paliers (tests qui
        // construisent `LoyaltyProgram` directement, ou programme historique
        // non passé par la commande de migration) — reproduit exactement le
        // fallback mono-palier de l'ancien `RewardTierService`. Le cashback
        // n'a par défaut aucun palier (comportement actuel : pas de cycle).
        if ($program->type === 'cashback') {
            return [];
        }

        $goal = (int) ($program->config['goal'] ?? 10);
        $title = (string) ($program->config['reward_description'] ?? '') ?: 'Récompense débloquée';

        return [[
            'id'                 => null,
            'order'              => 1,
            'goal'               => max(1, $goal),
            'level_name'         => null,
            'reward_description' => $title,
            'validity_days'      => $program->config['reward_validity_days'] ?? null,
        ]];
    }

    public function lifetimeCashback(LoyaltyCard $card): float
    {
        return (float) DB::table('loyalty_transactions')
            ->where('loyalty_card_id', $card->id)
            ->where('type', 'cashback_earn')
            ->where('status', 'valid')
            ->sum('value');
    }

    /**
     * Métrique multi-palier : jamais reset. Cashback = cashback cumulé à
     * vie. Tampons/Achats = `progress['stamps_current']`, qui n'est plus
     * remis à zéro dès qu'un programme a 2+ paliers (voir
     * `MerchantDashboardController::grantStampOrPoints`).
     */
    private function lifetimeMetric(LoyaltyCard $card): float
    {
        return $card->loyaltyProgram?->type === 'cashback'
            ? $this->lifetimeCashback($card)
            : (float) ($card->progress['stamps_current'] ?? 0);
    }

    /** @return array{level_name: ?string, percent_to_next: ?int, is_max_level: bool, tiers: array} */
    public function resolve(LoyaltyCard $card): array
    {
        $tiers = $this->tiers($card->loyaltyProgram);

        if (count($tiers) <= 1) {
            return ['level_name' => null, 'percent_to_next' => null, 'is_max_level' => false, 'tiers' => []];
        }

        $metric = $this->lifetimeMetric($card);

        $current = null;
        $next = null;
        foreach ($tiers as $tier) {
            if ($tier['goal'] <= $metric) {
                $current = $tier;
            } else {
                $next = $tier;
                break;
            }
        }

        $tiersWithStatus = collect($tiers)->values()->map(function ($tier, $i) use ($metric, $next) {
            $status = $tier['goal'] <= $metric
                ? 'reached'
                : ($next !== null && $tier['order'] === $next['order'] ? 'current' : 'upcoming');

            return [...$tier, 'icon' => $this->iconForRank($i + 1), 'status' => $status];
        })->all();

        if ($current === null) {
            $firstGoal = $tiers[0]['goal'];

            return [
                'level_name'      => null,
                'percent_to_next' => (int) round(max(0, min(100, ($metric / $firstGoal) * 100))),
                'is_max_level'    => false,
                'tiers'           => $tiersWithStatus,
            ];
        }

        if ($next === null) {
            return [
                'level_name'      => $current['level_name'],
                'percent_to_next' => null,
                'is_max_level'    => true,
                'tiers'           => $tiersWithStatus,
            ];
        }

        $span = $next['goal'] - $current['goal'];
        $percent = $span > 0 ? (($metric - $current['goal']) / $span) * 100 : 0;

        return [
            'level_name'      => $current['level_name'],
            'percent_to_next' => (int) round(max(0, min(100, $percent))),
            'is_max_level'    => false,
            'tiers'           => $tiersWithStatus,
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=LoyaltyTierServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Loyalty/LoyaltyTierService.php tests/Unit/Services/Loyalty/LoyaltyTierServiceTest.php
git commit -m "feat: add LoyaltyTierService (fusion niveau+récompense par palier)"
```

---

### Task 3: Brancher `LoyaltyCard` sur `LoyaltyTierService`

**Files:**
- Modify: `app/Models/LoyaltyCard.php`
- Modify: `tests/Feature/Merchant/AddStampTest.php:265-329` (`test_response_exposes_goal_percent_and_level`, `test_level_progresses_with_lifetime_completed_cycles`)

**Interfaces:**
- Consumes: `LoyaltyTierService::resolve()`, `::tiers()` (Task 2).
- Produces: `LoyaltyCard::level` reste un array `{name,percent_to_next,is_max_level}` mais peut désormais valoir `null` (mono-palier). Nouveau append `LoyaltyCard::tiers` (array, vide si mono-palier).

- [ ] **Step 1: Update the two now-outdated tests first (TDD against the new contract)**

Replace `test_response_exposes_goal_percent_and_level` (lines 265-285) in `tests/Feature/Merchant/AddStampTest.php`:

```php
    public function test_response_exposes_goal_percent_and_level(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 5],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $card->update(['progress' => ['stamps_current' => 2]]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps");

        $response->assertOk();
        // 2 + 1 = 3 sur 5 -> 60%.
        $this->assertSame(5, $card->fresh()->goal);
        $this->assertSame(60, $card->fresh()->percent);
        // Un seul palier configuré : pas de système de niveau affiché.
        $this->assertNull($card->fresh()->level);
    }
```

Replace `test_level_progresses_with_lifetime_completed_cycles` (lines 287-329):

```php
    public function test_level_progresses_through_multi_tier_program(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => [],
        ]);
        \App\Models\LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 2, 'level_name' => 'Bronze', 'reward_description' => 'Café offert',
        ]);
        \App\Models\LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 2,
            'goal' => 4, 'level_name' => 'Or', 'reward_description' => 'Menu offert',
        ]);
        $card = $this->cardFor($restaurant, $program);

        // Avant le premier palier : pas encore de niveau.
        $this->assertNull($card->fresh()->level['name']);

        // 2 tampons -> palier Bronze atteint pile.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        $level = $card->fresh()->level;
        $this->assertSame('Bronze', $level['name']);
        $this->assertSame(0, $level['percent_to_next']);
        $this->assertFalse($level['is_max_level']);

        // 2 tampons de plus -> Or, niveau max, plafonné (pas de second cycle).
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        $level = $card->fresh()->level;
        $this->assertSame('Or', $level['name']);
        $this->assertTrue($level['is_max_level']);
    }
```

- [ ] **Step 2: Run to verify these two fail**

Run: `php artisan test --filter=AddStampTest`
Expected: The two updated tests FAIL (level still returns `'Membre'` array, multi-tier program has no `loyalty_program_tiers` created yet so `LoyaltyCard::tiers` relation call errors or behaves like mono). All other `AddStampTest` tests still PASS (untouched mono-palier path).

- [ ] **Step 3: Wire the model**

In `app/Models/LoyaltyCard.php`, replace `$appends` line and the three accessors:

```php
    protected $appends = ['goal', 'percent', 'level', 'tiers', 'cashback_available_fcfa'];
```

Replace `getGoalAttribute()`:

```php
    public function getGoalAttribute(): ?int
    {
        $program = $this->loyaltyProgram;
        if (! $program || $program->type === 'cashback') {
            return null;
        }

        $service = app(\App\Services\Loyalty\LoyaltyTierService::class);
        $tiers = $service->tiers($program);

        if (count($tiers) <= 1) {
            // Mono-palier : comportement cycle répété inchangé — objectif
            // constant, span du palier unique.
            return $tiers[0]['goal'] ?? 10;
        }

        // Multi-palier : objectif = écart jusqu'au prochain palier non
        // atteint (ou jusqu'au premier palier si aucun n'est encore atteint).
        $resolved = $service->resolve($this);
        $current = (float) ($this->progress['stamps_current'] ?? 0);
        foreach ($tiers as $i => $tier) {
            if ($tier['goal'] > $current) {
                $previousGoal = $i > 0 ? $tiers[$i - 1]['goal'] : 0;

                return $tier['goal'] - $previousGoal;
            }
        }

        return null; // niveau max atteint, pas de palier suivant.
    }
```

Replace `getPercentAttribute()`:

```php
    public function getPercentAttribute(): int
    {
        $program = $this->loyaltyProgram;
        if (! $program) {
            return 0;
        }

        $service = app(\App\Services\Loyalty\LoyaltyTierService::class);
        $tiers = $service->tiers($program);

        if ($program->type === 'cashback') {
            return $this->level['percent_to_next'] ?? 0;
        }

        if (count($tiers) > 1) {
            return $this->level['percent_to_next'] ?? 100;
        }

        $goal = $this->goal;
        if (! $goal || $goal <= 0) {
            return 0;
        }

        $current = (int) ($this->progress['stamps_current'] ?? 0);

        return (int) round(max(0, min(100, ($current / $goal) * 100)));
    }
```

Replace `getLevelAttribute()`:

```php
    /** Niveau de fidélité — `null` tant que le programme n'a qu'un seul palier configuré (voir `LoyaltyTierService`). */
    public function getLevelAttribute(): ?array
    {
        $resolved = app(\App\Services\Loyalty\LoyaltyTierService::class)->resolve($this);

        return $resolved['level_name'] === null && $resolved['tiers'] === []
            ? null
            : [
                'name'            => $resolved['level_name'],
                'percent_to_next' => $resolved['percent_to_next'],
                'is_max_level'    => $resolved['is_max_level'],
            ];
    }

    /** Roadmap des paliers (vide si un seul palier configuré) — pour la vue "progression" côté client. */
    public function getTiersAttribute(): array
    {
        return app(\App\Services\Loyalty\LoyaltyTierService::class)->resolve($this)['tiers'];
    }
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=AddStampTest`
Expected: All PASS, including the two rewritten ones.

Run: `php artisan test` (full suite)
Expected: PASS — `LoyaltyReward`/`LoyaltyCardController` tests that read `$card->level` unaffected (none assert `'Membre'` elsewhere — verify with `grep -rn "'Membre'" tests/` before running, should now be empty).

- [ ] **Step 5: Commit**

```bash
git add app/Models/LoyaltyCard.php tests/Feature/Merchant/AddStampTest.php
git commit -m "feat: LoyaltyCard délègue goal/percent/level/tiers à LoyaltyTierService"
```

---

### Task 4: Déblocage multi-palier dans `MerchantDashboardController`

**Files:**
- Modify: `app/Http/Controllers/Api/MerchantDashboardController.php:172-216` (`grantCashback`), `:302-438` (`grantStampOrPoints`)
- Test: `tests/Feature/Merchant/AddStampTest.php` (append), `tests/Feature/Merchant/CashbackTierTest.php` (new)

**Interfaces:**
- Consumes: `LoyaltyTierService::tiers()`, `::lifetimeCashback()` (Task 2).
- Produces: `LoyaltyReward` créées avec `program_tier_id` renseigné pour les programmes multi-palier ; comportement mono-palier strictement inchangé.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Merchant/AddStampTest.php` (before the final `}`):

```php
    public function test_multi_tier_stamps_unlocks_each_tier_once_and_caps_at_max(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'stamps', 'config' => [],
        ]);
        \App\Models\LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 2, 'level_name' => 'Bronze', 'reward_description' => 'Café offert',
        ]);
        \App\Models\LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 2,
            'goal' => 4, 'level_name' => 'Or', 'reward_description' => 'Menu offert',
        ]);
        $card = $this->cardFor($restaurant, $program);

        // 1er tampon : aucun palier atteint.
        $r1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps");
        $r1->assertJsonPath('rewards_unlocked_count', 0);
        $this->assertSame(1, $card->fresh()->progress['stamps_current']);

        // 2e tampon : palier Bronze (2) atteint -> 1 récompense, pas de reset.
        $r2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps");
        $r2->assertJsonPath('rewards_unlocked_count', 1);
        $this->assertSame(2, $card->fresh()->progress['stamps_current']);
        $reward = \App\Models\LoyaltyReward::where('loyalty_card_id', $card->id)->first();
        $this->assertSame('Café offert', $reward->title);
        $bronzeTier = \App\Models\LoyaltyProgramTier::where('level_name', 'Bronze')->first();
        $this->assertSame($bronzeTier->id, $reward->program_tier_id);

        // 3e et 4e tampons -> palier Or (4) atteint -> 1 récompense de plus (2 au total).
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $r4 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps");
        $r4->assertJsonPath('rewards_unlocked_count', 1);
        $this->assertSame(2, \App\Models\LoyaltyReward::where('loyalty_card_id', $card->id)->count());

        // 5e tampon : plus aucun palier à débloquer (plafonné, pas de second cycle).
        $r5 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps");
        $r5->assertJsonPath('rewards_unlocked_count', 0);
        $this->assertSame(2, \App\Models\LoyaltyReward::where('loyalty_card_id', $card->id)->count());
        $this->assertSame(5, $card->fresh()->progress['stamps_current']);
    }
```

Create `tests/Feature/Merchant/CashbackTierTest.php`:

```php
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
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter=AddStampTest,CashbackTierTest`
Expected: FAIL — multi-tier stamps still resets every 2 tampons (old cycle logic runs regardless of tier count); cashback never creates any `LoyaltyReward`.

- [ ] **Step 3: Implement**

In `app/Http/Controllers/Api/MerchantDashboardController.php`, replace the `use` import `use App\Services\Loyalty\RewardTierService;` with `use App\Services\Loyalty\LoyaltyTierService;`.

Replace `grantCashback()` (lines 172-216) — insert tier-unlock logic after the balance update, before dispatching `LoyaltyCardUpdated`:

```php
    private function grantCashback(
        Request $request,
        Restaurant $restaurant,
        LoyaltyCard $loyaltyCard,
        \App\Models\LoyaltyProgram $program,
    ): JsonResponse {
        $request->validate([
            'amount_fcfa' => ['required', 'numeric', 'min:1'],
        ], [
            'amount_fcfa.required' => 'Le montant de l\'achat est requis pour ce programme.',
        ]);

        $tierService = app(LoyaltyTierService::class);
        $tiers = $tierService->tiers($program);
        $metricBefore = $tierService->lifetimeCashback($loyaltyCard);

        $amountFcfa = (float) $request->input('amount_fcfa');
        $percentage = (float) ($program->config['cashback_percentage'] ?? 0);
        $earnedFcfa = round($amountFcfa * $percentage / 100, 2);

        $restaurantId = $restaurant->id;
        $createdRewardIds = [];

        DB::transaction(function () use (
            $loyaltyCard, $earnedFcfa, $amountFcfa, $tiers, $metricBefore, $restaurantId, &$createdRewardIds,
        ) {
            $loyaltyCard->update([
                'cashback_balance_fcfa' => $loyaltyCard->cashback_balance_fcfa + $earnedFcfa,
                'last_activity_at'      => now(),
            ]);

            DB::table('loyalty_transactions')->insert([
                'loyalty_card_id'       => $loyaltyCard->id,
                'type'                  => 'cashback_earn',
                'value'                 => $earnedFcfa,
                'montant_commande_fcfa' => $amountFcfa,
                'validation_method'     => 'merchant_app',
                'status'                => 'valid',
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);

            $metricAfter = $metricBefore + $earnedFcfa;
            foreach ($this->crossedTiers($tiers, $metricBefore, $metricAfter) as $tier) {
                $reward = \App\Models\LoyaltyReward::create([
                    'loyalty_card_id' => $loyaltyCard->id,
                    'restaurant_id'   => $restaurantId,
                    'program_tier_id' => $tier['id'],
                    'title'           => $tier['reward_description'],
                    'unlocked_at'     => now(),
                    'expires_at'      => $tier['validity_days'] ? now()->addDays((int) $tier['validity_days']) : null,
                ]);
                $createdRewardIds[] = $reward->id;
            }
        });

        $freshCard = $loyaltyCard->fresh()->load(['client', 'loyaltyProgram']);
        LoyaltyCardUpdated::dispatch($freshCard);

        foreach (\App\Models\LoyaltyReward::whereIn('id', $createdRewardIds)->get() as $reward) {
            $reward->setRelation('loyaltyCard', $freshCard);
            LoyaltyRewardUpdated::dispatch($reward);
        }

        return response()->json([
            'message'         => number_format($earnedFcfa, 0, ',', ' ') . ' FCFA de cashback crédités.',
            'reward_unlocked' => count($createdRewardIds) > 0,
            'rewards_unlocked_count' => count($createdRewardIds),
            'cashback_earned' => $earnedFcfa,
            'client'          => $this->cardData($freshCard),
        ]);
    }

    /**
     * Paliers franchis entre `$before` et `$after` (métrique croissante,
     * jamais reset) :
     * - 1 seul palier configuré : répété à chaque multiple entier franchi
     *   (ex. tous les 1000 FCFA de cashback cumulés).
     * - 2+ paliers : chacun ne peut être franchi qu'une fois dans la vie de
     *   la carte (seuils strictement croissants), plafonné au dernier.
     *
     * @param array $tiers Depuis `LoyaltyTierService::tiers()`.
     * @return array Sous-ensemble de `$tiers` (avec doublons possibles si mono-palier).
     */
    private function crossedTiers(array $tiers, float $before, float $after): array
    {
        if (count($tiers) === 0) {
            return [];
        }

        if (count($tiers) === 1) {
            $goal = $tiers[0]['goal'];
            $crossedBefore = intdiv((int) $before, $goal);
            $crossedAfter = intdiv((int) $after, $goal);

            return array_fill(0, max(0, $crossedAfter - $crossedBefore), $tiers[0]);
        }

        return array_values(array_filter(
            $tiers,
            fn ($tier) => $tier['goal'] > $before && $tier['goal'] <= $after,
        ));
    }
```

In `grantStampOrPoints()` (lines 302-438), replace the tier-resolution and unlock block. Replace:

```php
        $tierService = app(RewardTierService::class);
        $tiers = $tierService->tiers($program);
        $tierIndex = $tierService->completedCycles($loyaltyCard);

        $progress = $loyaltyCard->progress ?? [];
        $current = (int) ($progress['stamps_current'] ?? 0) + $earned;

        // Un seul gros achat peut franchir l'objectif plusieurs fois d'un
        // coup (ex. objectif 500, +1050 points) : chaque franchissement
        // débloque sa propre récompense (son propre palier si plusieurs sont
        // configurés — `config['rewards']`), et le reste doit être conservé
        // pour le nouveau cycle plutôt que d'être perdu à un simple reset.
        $unlockedTiers = []; // [['span' => int, 'title' => string, 'validity_days' => ?int], ...]
        while (true) {
            $span = $tierService->spanFor($tiers, $tierIndex);
            if ($current < $span) {
                break;
            }
            $current -= $span;
            $unlockedTiers[] = [
                'span'          => $span,
                'title'         => $tierService->titleFor($tiers, $tierIndex),
                'validity_days' => $tierService->validityDaysFor($tiers, $tierIndex),
            ];
            $tierIndex++;
        }
        $cyclesCompleted = count($unlockedTiers);
        $rewardUnlocked = $cyclesCompleted > 0;
```

with:

```php
        $tierService = app(LoyaltyTierService::class);
        $tiers = $tierService->tiers($program);

        $progress = $loyaltyCard->progress ?? [];
        $before = (int) ($progress['stamps_current'] ?? 0);

        $unlockedTiers = []; // [['reward_description' => string, 'validity_days' => ?int, 'id' => ?int], ...]
        if (count($tiers) <= 1) {
            // Mono-palier : comportement cycle répété inchangé — un gros
            // achat peut franchir l'objectif plusieurs fois d'un coup, le
            // reste est conservé pour le nouveau cycle plutôt que perdu.
            $goal = $tiers[0]['goal'] ?? 10;
            $current = $before + $earned;
            while ($current >= $goal) {
                $current -= $goal;
                $unlockedTiers[] = $tiers[0];
            }
        } else {
            // Multi-palier : cumulatif à vie, jamais reset, plafonné.
            $current = $before + $earned;
            $unlockedTiers = $this->crossedTiers($tiers, $before, $current);
        }
        $cyclesCompleted = count($unlockedTiers);
        $rewardUnlocked = $cyclesCompleted > 0;
```

Replace the reward-creation loop inside the `DB::transaction` callback (still referencing `$unlockedTiers`, previously keyed `'title'`/`'validity_days'`, now `'reward_description'`/`'validity_days'`/`'id'`):

```php
            foreach ($unlockedTiers as $tier) {
                $reward = \App\Models\LoyaltyReward::create([
                    'loyalty_card_id' => $loyaltyCard->id,
                    'restaurant_id'   => $restaurantId,
                    'program_tier_id' => $tier['id'],
                    'title'           => $tier['reward_description'],
                    'unlocked_at'     => now(),
                    'expires_at'      => $tier['validity_days'] ? now()->addDays((int) $tier['validity_days']) : null,
                ]);
                $createdRewardIds[] = $reward->id;
            }
```

Remove the now-unused `foreach ($unlockedTiers as $tier) { DB::table('loyalty_transactions')->insert(['type' => 'cycle_completed', ...]); ... }` block entirely (the `cycle_completed` signal existed only to feed `RewardTierService::completedCycles()`/`LoyaltyLevelService`, both replaced — multi-tier crossing is now detected from `$before`/`$current` directly, mono-tier no longer needs an index at all).

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=AddStampTest,CashbackTierTest`
Expected: PASS

Run: `php artisan test`
Expected: PASS (full suite — mono-palier stamps/spend tests untouched since `count($tiers) <= 1` branch is behaviorally identical to before).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MerchantDashboardController.php \
        tests/Feature/Merchant/AddStampTest.php tests/Feature/Merchant/CashbackTierTest.php
git commit -m "feat: déblocage multi-palier cumulatif (stamps/spend/cashback)"
```

---

### Task 5: Payloads temps réel (`LoyaltyCardUpdated`/`LoyaltyRewardUpdated`)

**Files:**
- Modify: `app/Events/LoyaltyCardUpdated.php:37-52`
- Modify: `app/Events/LoyaltyRewardUpdated.php:46-52`
- Modify: `tests/Feature/Merchant/RewardRealtimeTest.php` (append assertions)

**Interfaces:**
- Produces: `LoyaltyCardUpdated::broadcastWith()` gagne la clé `'tiers'` (= `$this->card->tiers`). `LoyaltyRewardUpdated::broadcastWith()` gagne `'program_tier_id'`, `'level_name'`, `'icon'`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Merchant/RewardRealtimeTest.php` (adapt to the file's existing fixture pattern — read the file first to match its exact restaurant/card/program setup helper before inserting):

```php
    public function test_card_updated_broadcast_includes_tiers(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = \App\Models\LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'stamps', 'config' => [],
        ]);
        \App\Models\LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 2, 'level_name' => 'Bronze', 'reward_description' => 'Café offert',
        ]);
        \App\Models\LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 2,
            'goal' => 4, 'level_name' => 'Or', 'reward_description' => 'Menu offert',
        ]);
        $card = $this->cardFor($restaurant, $program);

        \Illuminate\Support\Facades\Event::fake([\App\Events\LoyaltyRewardUpdated::class]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\LoyaltyRewardUpdated::class, function ($event) {
            $payload = $event->broadcastWith();

            return $payload['level_name'] === 'Bronze' && $payload['icon'] === '🥉' && $payload['program_tier_id'] !== null;
        });
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=RewardRealtimeTest`
Expected: FAIL — `broadcastWith()` doesn't include `level_name`/`icon`/`program_tier_id` yet.

- [ ] **Step 3: Implement**

In `app/Events/LoyaltyCardUpdated.php`, add to the `broadcastWith()` return array (after `'level'`):

```php
            'tiers'                 => $this->card->tiers,
```

In `app/Events/LoyaltyRewardUpdated.php`, replace `broadcastWith()`:

```php
    public function broadcastWith(): array
    {
        $tier = $this->reward->programTier;

        return [
            'id'              => $this->reward->id,
            'status'          => $this->reward->status,
            'program_tier_id' => $this->reward->program_tier_id,
            'level_name'      => $tier?->level_name,
            'icon'            => $tier
                ? app(\App\Services\Loyalty\LoyaltyTierService::class)->iconForRank($tier->order)
                : null,
        ];
    }
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=RewardRealtimeTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Events/LoyaltyCardUpdated.php app/Events/LoyaltyRewardUpdated.php tests/Feature/Merchant/RewardRealtimeTest.php
git commit -m "feat: paliers/niveaux dans les payloads temps réel Reverb"
```

---

### Task 6: API de configuration marchand (`tiers[]` remplace `rewards[]`/`levels[]`)

**Files:**
- Modify: `app/Http/Requests/Auth/StoreLoyaltyProgramRequest.php:19-96`
- Modify: `app/Http/Controllers/Api/LoyaltyProgramController.php:22-80`
- Modify: `app/Http/Controllers/Api/RestaurantAuthController.php:392-397`
- Test: `tests/Feature/Merchant/LoyaltyProgramCreationTest.php` (append)

**Interfaces:**
- Produces: `POST /api/loyalty-programs` accepte `tiers: [{goal,level_name,reward_description,validity_days}]` (requis pour stamps/spend, optionnel pour cashback). `GET` profil marchand (`RestaurantAuthController`) expose `loyalty_program.config.tiers` (liste, dérivée de la table, pas stockée dans la colonne `config`).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Merchant/LoyaltyProgramCreationTest.php`:

```php
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
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --filter=LoyaltyProgramCreationTest`
Expected: FAIL — `tiers` field not validated/persisted yet.

- [ ] **Step 3: Implement**

In `app/Http/Requests/Auth/StoreLoyaltyProgramRequest.php`, replace the `'rewards'`, `'rewards.*.goal'`, `'rewards.*.reward_description'`, `'rewards.*.validity_days'`, `'levels'`, `'levels.*.name'`, `'levels.*.threshold'` rules (lines 35-81) with:

```php
            'tiers'                   => [
                'required_unless:mode,cashback',
                'array',
                'min:1',
                function ($attribute, $value, $fail) {
                    if (is_array($value)) {
                        $lastGoal = 0;
                        foreach ($value as $index => $tier) {
                            $goal = isset($tier['goal']) ? (int) $tier['goal'] : 0;
                            if ($goal <= $lastGoal) {
                                $fail('Le palier #' . ($index + 1) . " ($goal) doit être strictement supérieur au palier précédent ($lastGoal).");

                                return;
                            }
                            $lastGoal = $goal;
                        }
                    }
                },
            ],
            'tiers.*.goal'                => ['required', 'integer', 'min:1', 'max:1000000'],
            'tiers.*.level_name'          => ['nullable', 'string', 'max:100'],
            'tiers.*.reward_description'  => ['required', 'string', 'max:255'],
            // Durée de validité propre à ce palier — `null` = utilise
            // `reward_validity_days` (valeur par défaut du programme).
            'tiers.*.validity_days'       => ['nullable', 'integer', 'min:1', 'max:3650'],
```

Keep `'reward_description'` (program-level fallback, only used pre-migration by `LoyaltyTierService`'s legacy branch) and `'goal'` rules unchanged — they're no longer submitted by the Flutter app but stay valid/optional so nothing else breaks; change `'goal'` rule from `required_unless:mode,cashback` to `nullable` (it's superseded by `tiers.*.goal`):

```php
            'goal'                    => ['nullable', 'integer', 'min:1', 'max:1000000'],
```

In `app/Http/Controllers/Api/LoyaltyProgramController.php`, replace the whole `store()` body:

```php
    public function store(StoreLoyaltyProgramRequest $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $data = $request->validated();
        $isCashback = $data['mode'] === 'cashback';

        $program = $restaurant->loyaltyProgram()->updateOrCreate(
            ['restaurant_id' => $restaurant->id],
            [
                'name'      => $restaurant->name ?? 'Programme de fidélité',
                'type'      => $data['mode'],
                'is_active' => true,
                'config'    => [
                    'reward_validity_days'    => $data['reward_validity_days'] ?? null,
                    'show_review_button'      => $data['show_review_button'] ?? false,
                    'google_review_url'       => $data['google_review_url'] ?? null,
                    'color_primary'           => $data['color_primary'],
                    'color_secondary'         => $data['color_secondary'],
                    'stamp_design_type'       => $data['stamp_design_type'],
                    'stamp_emoji'             => $data['stamp_emoji'] ?? null,
                    'stamp_icon'              => $data['stamp_icon'] ?? null,
                    'card_decoration_pattern' => $data['card_decoration_pattern'] ?? null,
                    'card_gradient_type'      => $data['card_gradient_type'] ?? null,
                    'logo_url'                => $data['logo_url'] ?? null,
                    'fcfa_per_point'          => $data['mode'] === 'spend'
                        ? ($data['fcfa_per_point'] ?? 100)
                        : null,
                    'cashback_percentage'        => $isCashback ? $data['cashback_percentage'] : null,
                    'cashback_redeem_cap_percent' => $isCashback ? ($data['cashback_redeem_cap_percent'] ?? null) : null,
                    'cashback_expiry_days'       => $isCashback ? ($data['cashback_expiry_days'] ?? null) : null,
                ],
            ],
        );

        $program->tiers()->delete();
        foreach ($data['tiers'] ?? [] as $index => $tier) {
            $program->tiers()->create([
                'order'               => $index + 1,
                'goal'                => (int) $tier['goal'],
                'level_name'          => $tier['level_name'] ?? null,
                'reward_description'  => $tier['reward_description'],
                'validity_days'       => $tier['validity_days'] ?? null,
            ]);
        }

        return response()->json([
            'message'         => 'Programme de fidélité enregistré.',
            'loyalty_program' => $program->load('tiers'),
        ], 201);
    }
```

In `app/Http/Controllers/Api/RestaurantAuthController.php`, replace lines 392-397:

```php
            'loyalty_program'     => $restaurant->loyaltyProgram
                ? [
                    'type'   => $restaurant->loyaltyProgram->type,
                    'config' => [
                        ...$restaurant->loyaltyProgram->config ?? [],
                        'tiers' => $restaurant->loyaltyProgram->tiers->map(fn ($t) => [
                            'goal'                => $t->goal,
                            'level_name'          => $t->level_name,
                            'reward_description'  => $t->reward_description,
                            'validity_days'       => $t->validity_days,
                        ])->all(),
                    ],
                ]
                : null,
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter=LoyaltyProgramCreationTest`
Expected: PASS

Run: `php artisan test`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/Auth/StoreLoyaltyProgramRequest.php app/Http/Controllers/Api/LoyaltyProgramController.php \
        app/Http/Controllers/Api/RestaurantAuthController.php tests/Feature/Merchant/LoyaltyProgramCreationTest.php
git commit -m "feat: API programme fidélité accepte tiers[] unifiés"
```

---

### Task 7: Migration des programmes existants + nettoyage des anciens services

**Files:**
- Create: `app/Console/Commands/MigrateLoyaltyProgramTiers.php`
- Test: `tests/Feature/Console/MigrateLoyaltyProgramTiersTest.php`
- Delete: `app/Services/Loyalty/RewardTierService.php`, `app/Services/Loyalty/LoyaltyLevelService.php`

**Interfaces:**
- Produces: `php artisan loyalty:migrate-tiers` — commande one-shot, idempotente (skip un programme qui a déjà des `tiers()`).

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=MigrateLoyaltyProgramTiersTest`
Expected: FAIL — command `loyalty:migrate-tiers` doesn't exist.

- [ ] **Step 3: Implement**

`app/Console/Commands/MigrateLoyaltyProgramTiers.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\LoyaltyProgram;
use Illuminate\Console\Command;

/**
 * Migration one-shot (pas une migration de schéma — logique métier) : fusionne
 * les anciens `config['rewards']`/`config['levels']` de chaque programme dans
 * la nouvelle table `loyalty_program_tiers`. Idempotente : ignore un
 * programme qui a déjà des paliers.
 */
class MigrateLoyaltyProgramTiers extends Command
{
    protected $signature = 'loyalty:migrate-tiers';

    protected $description = 'Fusionne config[rewards]/config[levels] existants en paliers unifiés (une fois)';

    public function handle(): int
    {
        $count = 0;

        LoyaltyProgram::with('tiers')->chunk(50, function ($programs) use (&$count) {
            foreach ($programs as $program) {
                if ($program->tiers->isNotEmpty()) {
                    continue;
                }

                $rewards = $program->config['rewards'] ?? null;
                $levels = $program->config['levels'] ?? null;

                if (! is_array($rewards) || count($rewards) === 0) {
                    $goal = $program->config['goal'] ?? null;
                    if ($goal === null) {
                        continue; // cashback sans rewards/levels/goal : aucun palier implicite.
                    }
                    $rewards = [[
                        'goal'               => $goal,
                        'reward_description' => $program->config['reward_description'] ?? 'Récompense débloquée',
                    ]];
                }

                $rewards = collect($rewards)->sortBy('goal')->values()->all();
                $sameCount = is_array($levels) && count($levels) === count($rewards);
                $levels = $sameCount ? collect($levels)->sortBy('threshold')->values()->all() : null;

                foreach ($rewards as $index => $reward) {
                    $program->tiers()->create([
                        'order'               => $index + 1,
                        'goal'                => (int) $reward['goal'],
                        'level_name'          => $sameCount ? $levels[$index]['name'] : 'Palier ' . ($index + 1),
                        'reward_description'  => $reward['reward_description'] ?? 'Récompense débloquée',
                        'validity_days'       => $reward['validity_days'] ?? null,
                    ]);
                }

                $count++;
            }
        });

        $this->info("{$count} programme(s) migré(s) vers la nouvelle table de paliers.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run tests, then remove the now-dead services**

Run: `php artisan test --filter=MigrateLoyaltyProgramTiersTest`
Expected: PASS

Run: `grep -rn "RewardTierService\|LoyaltyLevelService" app tests` — expect zero matches (Tasks 3/4 already rewired every consumer). Then:

```bash
rm app/Services/Loyalty/RewardTierService.php app/Services/Loyalty/LoyaltyLevelService.php
```

Run: `php artisan test`
Expected: PASS — full suite green with the two old services gone.

- [ ] **Step 5: Commit and run the migration on the deployed database**

```bash
git add app/Console/Commands/MigrateLoyaltyProgramTiers.php tests/Feature/Console/MigrateLoyaltyProgramTiersTest.php
git rm app/Services/Loyalty/RewardTierService.php app/Services/Loyalty/LoyaltyLevelService.php
git commit -m "feat: commande de migration des programmes existants vers les paliers unifiés

Supprime RewardTierService/LoyaltyLevelService (fusionnés dans LoyaltyTierService, Task 2)."
```

Note for whoever deploys this: run `php artisan loyalty:migrate-tiers` once against the real database after this branch ships — it's not run automatically by `php artisan migrate`.

---

## Frontend (`Miva_Fid`)

### Task 8: Modèle `ProgramTier` (remplace `RewardTier` + `LoyaltyLevel`)

**Files:**
- Create: `lib/features/onboarding/models/program_tier.dart`
- Delete: `lib/features/onboarding/models/reward_tier.dart`, `lib/features/onboarding/models/loyalty_level.dart`

**Interfaces:**
- Produces: `class ProgramTier { final int goal; final String? levelName; final String rewardDescription; final int? validityDays; ... toJson()/fromJson() }`.

- [ ] **Step 1: Write the model**

`lib/features/onboarding/models/program_tier.dart`:

```dart
/// Palier unifié : objectif (seuil) + niveau (nom libre du marchand,
/// `null`/ignoré si un seul palier) + récompense. Remplace `RewardTier` et
/// `LoyaltyLevel`, auparavant deux systèmes indépendants.
class ProgramTier {
  final int goal;

  /// Nom du niveau — libre, masqué côté UI si un seul palier est configuré.
  final String? levelName;
  final String rewardDescription;

  /// Durée de validité (jours) propre à ce palier — `null` = utilise la
  /// valeur par défaut du programme (`reward_validity_days`).
  final int? validityDays;

  const ProgramTier({
    required this.goal,
    this.levelName,
    required this.rewardDescription,
    this.validityDays,
  });

  ProgramTier copyWith({
    int? goal,
    String? levelName,
    bool clearLevelName = false,
    String? rewardDescription,
    int? validityDays,
    bool clearValidityDays = false,
  }) {
    return ProgramTier(
      goal: goal ?? this.goal,
      levelName: clearLevelName ? null : (levelName ?? this.levelName),
      rewardDescription: rewardDescription ?? this.rewardDescription,
      validityDays:
          clearValidityDays ? null : (validityDays ?? this.validityDays),
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'goal': goal,
      'level_name': levelName,
      'reward_description': rewardDescription,
      if (validityDays != null) 'validity_days': validityDays,
    };
  }

  factory ProgramTier.fromJson(Map<String, dynamic> json) {
    return ProgramTier(
      goal: (json['goal'] as num?)?.toInt() ?? 10,
      levelName: json['level_name'] as String?,
      rewardDescription: (json['reward_description'] as String?) ?? '',
      validityDays: (json['validity_days'] as num?)?.toInt(),
    );
  }
}
```

- [ ] **Step 2: Delete the old models**

```bash
rm lib/features/onboarding/models/reward_tier.dart lib/features/onboarding/models/loyalty_level.dart
```

(This will break compilation of every consumer until Tasks 9-11 update them — that's expected mid-refactor; `flutter analyze` will be run at the end of Task 11 once every consumer is migrated.)

- [ ] **Step 3: Commit**

```bash
git add lib/features/onboarding/models/program_tier.dart
git rm lib/features/onboarding/models/reward_tier.dart lib/features/onboarding/models/loyalty_level.dart
git commit -m "feat: modèle ProgramTier unifié (remplace RewardTier+LoyaltyLevel)"
```

---

### Task 9: `onboardingNotifierProvider` sur `ProgramTier`

**Files:**
- Modify: `lib/features/onboarding/providers/onboarding_provider.dart`

**Interfaces:**
- Consumes: `ProgramTier` (Task 8).
- Produces: `OnboardingState.tiers: List<ProgramTier>` (remplace `.rewards`), `toLoyaltyProgramJson()` émet `'tiers'` au lieu de `'goal'`/`'reward_description'`/`'rewards'`, `hydrateFrom()` lit `config['tiers']`.

- [ ] **Step 1: Rewrite the provider**

In `lib/features/onboarding/providers/onboarding_provider.dart`:

Replace the import `import '../models/reward_tier.dart';` with `import '../models/program_tier.dart';`.

Replace every `RewardTier` occurrence with `ProgramTier`, and every `rewards`-named field/method with `tiers`:

- Constructor default: `this.rewards = const [RewardTier(goal: 10, rewardDescription: '')],` → `this.tiers = const [ProgramTier(goal: 10, rewardDescription: '')],`
- Field: `final List<RewardTier> rewards;` → `final List<ProgramTier> tiers;`
- `int get stampsRequired => rewards.isNotEmpty ? rewards.first.goal : 10;` → `int get stampsRequired => tiers.isNotEmpty ? tiers.first.goal : 10;`
- `String get rewardDescription => rewards.isNotEmpty ? rewards.first.rewardDescription : '';` → `String get rewardDescription => tiers.isNotEmpty ? tiers.first.rewardDescription : '';`
- `copyWith({... List<RewardTier>? rewards, ...})` → `copyWith({... List<ProgramTier>? tiers, ...})`, body `rewards: rewards ?? this.rewards,` → `tiers: tiers ?? this.tiers,`

Replace `toLoyaltyProgramJson()`:

```dart
  Map<String, dynamic> toLoyaltyProgramJson() {
    return {
      'mode': loyaltyMode,
      if (!isCashback) 'tiers': tiers.map((t) => t.toJson()).toList(),
      'show_review_button': showReviewButton,
      'google_review_url':
          (showReviewButton && googleReviewUrl.isNotEmpty) ? googleReviewUrl : null,
      'color_primary': colorPrimaryHex,
      'color_secondary': colorSecondaryHex,
      'stamp_design_type': stampDesignType,
      'stamp_emoji': stampEmoji.isEmpty ? null : stampEmoji,
      'stamp_icon': stampIcon.isEmpty ? null : stampIcon,
      'card_decoration_pattern': cardDecorationPattern,
      'card_gradient_type': cardGradientType,
      'logo_url': logoUrl,
      if (loyaltyMode == 'spend') 'fcfa_per_point': fcfaPerPoint,
      if (isCashback) 'cashback_percentage': cashbackPercentage,
      if (isCashback && cashbackRedeemCapPercent != null)
        'cashback_redeem_cap_percent': cashbackRedeemCapPercent,
      if (isCashback && cashbackExpiryDays != null)
        'cashback_expiry_days': cashbackExpiryDays,
    };
  }
```

In `OnboardingNotifier`, rename every method operating on rewards to operate on tiers, keeping the same call sites elsewhere working (Task 10/11 update the callers):

```dart
  void setStampsRequired(int v) {
    final (min, max) = switch (state.loyaltyMode) {
      'stamps' => (3, 50),
      'spend' => (50, 1000000),
      _ => (1, 1000000),
    };
    final clampedGoal = v.clamp(min, max);
    if (state.tiers.isEmpty) {
      state = state.copyWith(tiers: [ProgramTier(goal: clampedGoal, rewardDescription: '')]);
    } else {
      final updated = List<ProgramTier>.from(state.tiers);
      updated[0] = updated[0].copyWith(goal: clampedGoal);
      state = state.copyWith(tiers: updated);
    }
  }

  void setTiers(List<ProgramTier> tiers) {
    state = state.copyWith(tiers: tiers);
  }

  void addTier([ProgramTier? tier]) {
    final list = List<ProgramTier>.from(state.tiers);
    if (tier != null) {
      list.add(tier);
    } else {
      final lastGoal = list.isNotEmpty ? list.last.goal : 10;
      final step = state.loyaltyMode == 'stamps' ? 5 : 500;
      list.add(ProgramTier(goal: lastGoal + step, rewardDescription: ''));
    }
    state = state.copyWith(tiers: list);
  }

  void removeTier(int index) {
    if (state.tiers.length <= 1) return;
    final list = List<ProgramTier>.from(state.tiers);
    if (index >= 0 && index < list.length) {
      list.removeAt(index);
      state = state.copyWith(tiers: list);
    }
  }

  void updateTier(int index, ProgramTier tier) {
    final list = List<ProgramTier>.from(state.tiers);
    if (index >= 0 && index < list.length) {
      list[index] = tier;
      state = state.copyWith(tiers: list);
    }
  }
```

Replace `setRewardDescription()` body accordingly (`state.rewards` → `state.tiers`, `RewardTier` → `ProgramTier`, `rewards:` → `tiers:`).

In `hydrateFrom()`, replace the `List<RewardTier> loadedRewards` block:

```dart
    List<ProgramTier> loadedTiers = [];
    if (config['tiers'] is List) {
      for (final item in config['tiers'] as List) {
        if (item is Map<String, dynamic>) {
          loadedTiers.add(ProgramTier.fromJson(item));
        } else if (item is Map) {
          loadedTiers.add(ProgramTier.fromJson(Map<String, dynamic>.from(item)));
        }
      }
    }
    if (loadedTiers.isEmpty) {
      loadedTiers = [
        ProgramTier(
          goal: (int.tryParse(config['goal']?.toString() ?? '') ?? 10).clamp(1, 1000000),
          rewardDescription: text(config['reward_description']?.toString()),
        ),
      ];
    }
```

and change the `OnboardingState(...)` construction's `rewards: loadedRewards,` to `tiers: loadedTiers,`.

- [ ] **Step 2: Verify (compile-level, other files still reference old names — expected until Task 11)**

Run: `cd /home/othnelio/fcm/Miva_Fid && grep -n "rewards" lib/features/onboarding/providers/onboarding_provider.dart`
Expected: no matches (fully renamed).

- [ ] **Step 3: Commit**

```bash
git add lib/features/onboarding/providers/onboarding_provider.dart
git commit -m "feat: onboardingNotifierProvider utilise ProgramTier (paliers unifiés)"
```

---

### Task 10: Éditeur de paliers unifié + écran marchand fusionné

**Files:**
- Create: `lib/features/merchant/widgets/tier_editor_form.dart`
- Create: `lib/features/merchant/screens/programme_tiers_screen.dart`
- Delete: `lib/features/merchant/screens/programme_rewards_screen.dart`, `lib/features/merchant/screens/programme_levels_screen.dart`
- Modify: `lib/features/merchant/screens/programme_screen.dart:171-184`
- Modify: `lib/core/router/app_router.dart:43-44,582,587`

**Interfaces:**
- Produces: `TierEditorForm` — `StatefulWidget` réutilisable, `{required List<ProgramTier> initialTiers, required String goalUnit, required ValueChanged<List<ProgramTier>> onChanged, required GlobalKey<FormState> formKey}`, expose une liste de contrôleurs éditable (objectif, nom du niveau masqué si 1 seule ligne, récompense, validité).

- [ ] **Step 1: Build the shared editor widget**

`lib/features/merchant/widgets/tier_editor_form.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_text_styles.dart';
import '../../../core/widgets/app_input.dart';
import '../../onboarding/models/program_tier.dart';

/// Icônes de niveau — automatiques par rang, jamais choisies par le
/// marchand (miroir de `LoyaltyTierService::iconForRank` côté API).
const List<String> tierRankIcons = ['🥉', '🥈', '🥇', '💎', '👑'];
String iconForTierRank(int rank) =>
    rank >= 1 && rank <= tierRankIcons.length ? tierRankIcons[rank - 1] : '⭐';

/// Éditeur de paliers réutilisé par l'onboarding (step2) et les réglages
/// marchand — un palier = objectif + nom de niveau (masqué si un seul
/// palier) + récompense + validité optionnelle.
class TierEditorForm extends StatefulWidget {
  final List<ProgramTier> initialTiers;
  final String goalUnit;
  final ValueChanged<List<ProgramTier>> onChanged;

  const TierEditorForm({
    super.key,
    required this.initialTiers,
    required this.goalUnit,
    required this.onChanged,
  });

  @override
  State<TierEditorForm> createState() => TierEditorFormState();
}

class TierEditorFormState extends State<TierEditorForm> {
  final List<TextEditingController> _goalCtrls = [];
  final List<TextEditingController> _levelNameCtrls = [];
  final List<TextEditingController> _descCtrls = [];
  final List<TextEditingController> _validityCtrls = [];

  @override
  void initState() {
    super.initState();
    final tiers = widget.initialTiers.isEmpty
        ? [const ProgramTier(goal: 10, rewardDescription: '')]
        : widget.initialTiers;
    for (final tier in tiers) {
      _addController(tier);
    }
  }

  void _addController(ProgramTier tier) {
    _goalCtrls.add(TextEditingController(text: tier.goal.toString()));
    _levelNameCtrls.add(TextEditingController(text: tier.levelName ?? ''));
    _descCtrls.add(TextEditingController(text: tier.rewardDescription));
    _validityCtrls
        .add(TextEditingController(text: tier.validityDays?.toString() ?? ''));
  }

  void _emitChange() {
    widget.onChanged(currentTiers());
  }

  /// Snapshot lisible par l'appelant à tout moment (ex. juste avant soumission).
  List<ProgramTier> currentTiers() {
    return List.generate(_goalCtrls.length, (i) {
      return ProgramTier(
        goal: int.tryParse(_goalCtrls[i].text.trim()) ?? 10,
        levelName: _goalCtrls.length > 1 && _levelNameCtrls[i].text.trim().isNotEmpty
            ? _levelNameCtrls[i].text.trim()
            : null,
        rewardDescription: _descCtrls[i].text.trim(),
        validityDays: int.tryParse(_validityCtrls[i].text.trim()),
      );
    });
  }

  void addTier() {
    final lastGoal =
        _goalCtrls.isNotEmpty ? (int.tryParse(_goalCtrls.last.text) ?? 10) : 10;
    setState(() {
      _addController(ProgramTier(goal: lastGoal + 500, rewardDescription: ''));
    });
    _emitChange();
  }

  void removeTier(int index) {
    if (_goalCtrls.length <= 1) return;
    setState(() {
      _goalCtrls[index].dispose();
      _levelNameCtrls[index].dispose();
      _descCtrls[index].dispose();
      _validityCtrls[index].dispose();
      _goalCtrls.removeAt(index);
      _levelNameCtrls.removeAt(index);
      _descCtrls.removeAt(index);
      _validityCtrls.removeAt(index);
    });
    _emitChange();
  }

  @override
  void dispose() {
    for (final c in [..._goalCtrls, ..._levelNameCtrls, ..._descCtrls, ..._validityCtrls]) {
      c.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final isMultiTier = _goalCtrls.length > 1;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text('Vos paliers', style: AppTextStyles.labelBold()),
            Text(
              '${_goalCtrls.length} palier${_goalCtrls.length > 1 ? 's' : ''}',
              style: AppTextStyles.caption()
                  .copyWith(color: AppColors.merchant, fontWeight: FontWeight.bold),
            ),
          ],
        ),
        if (isMultiTier) ...[
          const SizedBox(height: Sp.xs),
          Text(
            'Chaque palier attribue un niveau nommé par vous et débloque sa '
            'propre récompense, sans jamais redescendre une fois atteint.',
            style: AppTextStyles.caption().copyWith(color: AppColors.textSecondary),
          ),
        ],
        const SizedBox(height: Sp.sm),
        ListView.separated(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          itemCount: _goalCtrls.length,
          separatorBuilder: (_, __) => const SizedBox(height: Sp.md),
          itemBuilder: (context, index) => Container(
            padding: const EdgeInsets.all(Sp.md),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: AppColors.border),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withValues(alpha: 0.03),
                  blurRadius: 10,
                  offset: const Offset(0, 4),
                ),
              ],
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                      decoration: BoxDecoration(
                        color: AppColors.merchantTint,
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Text(
                        '${iconForTierRank(index + 1)} Palier ${index + 1}',
                        style: AppTextStyles.caption()
                            .copyWith(color: AppColors.merchant, fontWeight: FontWeight.bold),
                      ),
                    ),
                    const Spacer(),
                    if (_goalCtrls.length > 1)
                      IconButton(
                        icon: const Icon(LucideIcons.trash2, size: 18, color: AppColors.danger),
                        onPressed: () => removeTier(index),
                        tooltip: 'Supprimer ce palier',
                      ),
                  ],
                ),
                const SizedBox(height: Sp.sm),
                AppInput(
                  label: 'Objectif (${widget.goalUnit}) *',
                  hint: 'Ex: 500',
                  controller: _goalCtrls[index],
                  keyboardType: TextInputType.number,
                  prefixIcon: LucideIcons.target,
                  accentColor: AppColors.merchant,
                  onChanged: (_) {
                    setState(() {});
                    _emitChange();
                  },
                  validator: (v) {
                    final val = v?.trim() ?? '';
                    if (val.isEmpty) return "L'objectif est obligatoire";
                    final parsed = int.tryParse(val);
                    if (parsed == null || parsed <= 0) {
                      return 'Veuillez entrer un nombre supérieur à 0';
                    }
                    if (index > 0) {
                      final prev = int.tryParse(_goalCtrls[index - 1].text.trim());
                      if (prev != null && parsed <= prev) {
                        return 'Doit être supérieur au palier précédent ($prev)';
                      }
                    }
                    return null;
                  },
                ),
                if (isMultiTier) ...[
                  const SizedBox(height: Sp.sm),
                  AppInput(
                    label: 'Nom du niveau *',
                    hint: 'Ex : Découverte, Habitué, VIP',
                    controller: _levelNameCtrls[index],
                    prefixIcon: LucideIcons.award,
                    accentColor: AppColors.merchant,
                    onChanged: (_) => _emitChange(),
                    validator: (v) => (v?.trim() ?? '').isEmpty
                        ? 'Le nom du niveau est obligatoire'
                        : null,
                  ),
                ],
                const SizedBox(height: Sp.sm),
                AppInput(
                  label: 'Récompense offerte *',
                  hint: 'Ex : 1 café offert, 10% de réduction',
                  controller: _descCtrls[index],
                  prefixIcon: LucideIcons.gift,
                  accentColor: AppColors.merchant,
                  maxLength: 255,
                  onChanged: (_) => _emitChange(),
                  validator: (v) => (v?.trim() ?? '').isEmpty
                      ? 'La description de la récompense est obligatoire'
                      : null,
                ),
                const SizedBox(height: Sp.sm),
                AppInput(
                  label: 'Validité (jours, optionnel)',
                  hint: "Ex: 30 — vide = pas d'expiration",
                  controller: _validityCtrls[index],
                  keyboardType: TextInputType.number,
                  prefixIcon: LucideIcons.calendarClock,
                  accentColor: AppColors.merchant,
                  onChanged: (_) => _emitChange(),
                  validator: (v) {
                    final trimmed = v?.trim() ?? '';
                    if (trimmed.isEmpty) return null;
                    final parsed = int.tryParse(trimmed);
                    if (parsed == null || parsed <= 0) {
                      return 'Veuillez entrer un nombre de jours supérieur à 0';
                    }
                    return null;
                  },
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
```

- [ ] **Step 2: Build the merged settings screen**

`lib/features/merchant/screens/programme_tiers_screen.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_text_styles.dart';
import '../../../core/widgets/app_button.dart';
import '../../onboarding/models/program_tier.dart';
import '../../onboarding/widgets/loyalty_card_preview.dart';
import '../providers/merchant_auth_provider.dart';
import '../providers/merchant_provider.dart';
import '../widgets/tier_editor_form.dart';
import '../../client/providers/settings_provider.dart';

class ProgrammeTiersScreen extends ConsumerStatefulWidget {
  const ProgrammeTiersScreen({super.key});

  @override
  ConsumerState<ProgrammeTiersScreen> createState() => _ProgrammeTiersScreenState();
}

class _ProgrammeTiersScreenState extends ConsumerState<ProgrammeTiersScreen> {
  final _formKey = GlobalKey<FormState>();
  final _tierEditorKey = GlobalKey<TierEditorFormState>();
  List<ProgramTier> _tiers = [];
  bool _saving = false;
  bool _initialized = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _initFromMerchant());
  }

  void _initFromMerchant() {
    final restaurant = ref.read(merchantAuthProvider).restaurant;
    final m = ref.read(merchantNotifierProvider).value;
    if (restaurant == null || m == null) return;

    final config = restaurant.loyaltyConfig;
    List<ProgramTier> loaded = [];
    if (config['tiers'] is List) {
      for (final item in config['tiers'] as List) {
        if (item is Map<String, dynamic>) {
          loaded.add(ProgramTier.fromJson(item));
        } else if (item is Map) {
          loaded.add(ProgramTier.fromJson(Map<String, dynamic>.from(item)));
        }
      }
    }
    if (loaded.isEmpty) {
      loaded = [ProgramTier(goal: m.stampsRequired, rewardDescription: m.rewardDescription ?? '')];
    }

    setState(() {
      _tiers = loaded;
      _initialized = true;
    });
  }

  String _goalUnit(String loyaltyMode) {
    switch (loyaltyMode) {
      case 'spend':
        return 'points / FCFA';
      case 'cashback':
        return 'FCFA de cashback cumulés';
      default:
        return 'tampons';
    }
  }

  Future<void> _save() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;

    setState(() => _saving = true);
    final tiers = _tierEditorKey.currentState?.currentTiers() ?? _tiers;

    try {
      await ref.read(merchantNotifierProvider.notifier).updateProgramme({
        'tiers': tiers.map((t) => t.toJson()).toList(),
      });
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(const SnackBar(content: Text('Paliers mis à jour avec succès')));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Erreur: $e')));
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    ref.watch(appBrightnessProvider);
    final merchantAsync = ref.watch(merchantNotifierProvider);
    final loyaltyMode = merchantAsync.value?.loyaltyMode ?? 'stamps';

    if (!_initialized) {
      return Scaffold(
        backgroundColor: AppColors.background,
        appBar: AppBar(title: const Text('Paliers')),
        body: const Center(child: CircularProgressIndicator()),
      );
    }

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        title: const Text('Paliers de fidélité'),
        backgroundColor: Colors.transparent,
        elevation: 0,
      ),
      body: SafeArea(
        child: Form(
          key: _formKey,
          autovalidateMode: AutovalidateMode.onUserInteraction,
          child: Column(
            children: [
              Expanded(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.all(Sp.md),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Aperçu de la carte', style: AppTextStyles.labelBold()),
                      const SizedBox(height: Sp.sm),
                      const LoyaltyCardPreview(previewStamps: 6),
                      const SizedBox(height: Sp.xl),
                      TierEditorForm(
                        key: _tierEditorKey,
                        initialTiers: _tiers,
                        goalUnit: _goalUnit(loyaltyMode),
                        onChanged: (t) => _tiers = t,
                      ),
                      const SizedBox(height: Sp.md),
                      OutlinedButton.icon(
                        onPressed: () => _tierEditorKey.currentState?.addTier(),
                        style: OutlinedButton.styleFrom(
                          minimumSize: const Size.fromHeight(48),
                          foregroundColor: AppColors.merchant,
                          side: const BorderSide(color: AppColors.merchant),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        icon: const Icon(LucideIcons.plus, size: 18),
                        label: Text(
                          'Ajouter un palier',
                          style: AppTextStyles.bodyMd()
                              .copyWith(color: AppColors.merchant, fontWeight: FontWeight.bold),
                        ),
                      ),
                      const SizedBox(height: Sp.xl),
                    ],
                  ),
                ),
              ),
              Padding(
                padding: EdgeInsets.fromLTRB(Sp.md, 0, Sp.md, MediaQuery.of(context).padding.bottom + Sp.md),
                child: AppButton.primary('Enregistrer',
                    icon: LucideIcons.save, onPressed: _save, loading: _saving),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
```

- [ ] **Step 3: Wire the router and the menu, delete the old screens**

```bash
rm lib/features/merchant/screens/programme_rewards_screen.dart lib/features/merchant/screens/programme_levels_screen.dart
```

In `lib/core/router/app_router.dart`, replace the two imports on lines 43-44:

```dart
import '../../features/merchant/screens/programme_tiers_screen.dart';
```

Replace the two routes at lines ~578-590 (the ones building `ProgrammeRewardsScreen`/`ProgrammeLevelsScreen`) with a single route:

```dart
                    GoRoute(
                      path: 'tiers',
                      pageBuilder: (_, __) => _slide(const ProgrammeTiersScreen()),
                    ),
```

(Keep the route's parent path/nesting identical to how the two replaced routes were nested — only the leaf segment and destination widget change; remove the now-orphaned `rewards`/`levels` leaf routes entirely.)

In `lib/features/merchant/screens/programme_screen.dart`, replace the two `_buildCategoryItem` calls for "Paliers de récompenses" and "Niveaux VIP (Statuts)" (lines 171-184) with a single entry:

```dart
                  _buildCategoryItem(
                    icon: LucideIcons.gift,
                    title: 'Paliers de fidélité',
                    subtitle: 'Objectifs, niveaux et récompenses de votre programme',
                    onTap: () => context.go('/merchant/more/programme/tiers'),
                  ),
                  const SizedBox(height: Sp.md),
```

Also in `programme_screen.dart`, `_initPreview()` reads `config['rewards']` into `RewardTier` — replace with `config['tiers']`/`ProgramTier` the same way as Task 10 Step 2's `_initFromMerchant()`, and change `ref.read(onboardingNotifierProvider.notifier)..setRewards(loadedRewards);` to `..setTiers(loadedTiers);`. Update the import from `reward_tier.dart` to `program_tier.dart`.

- [ ] **Step 4: Verify**

Run: `cd /home/othnelio/fcm/Miva_Fid && grep -rn "RewardTier\|LoyaltyLevel\|ProgrammeRewardsScreen\|ProgrammeLevelsScreen" lib --include="*.dart"`
Expected: zero matches in this task's files (`onboarding_provider.dart`/`merchant_step2_screen.dart` still pending — Task 11).

- [ ] **Step 5: Commit**

```bash
git add lib/features/merchant/widgets/tier_editor_form.dart lib/features/merchant/screens/programme_tiers_screen.dart \
        lib/features/merchant/screens/programme_screen.dart lib/core/router/app_router.dart
git rm lib/features/merchant/screens/programme_rewards_screen.dart lib/features/merchant/screens/programme_levels_screen.dart
git commit -m "feat: écran marchand unifié Paliers de fidélité (remplace Récompenses+Niveaux VIP)"
```

---

### Task 11: Onboarding step2 sur l'éditeur unifié

**Files:**
- Modify: `lib/features/onboarding/screens/merchant_step2_screen.dart`

**Interfaces:**
- Consumes: `TierEditorForm`, `ProgramTier` (Task 10/8).

- [ ] **Step 1: Rewrite the reward-tier section**

In `lib/features/onboarding/screens/merchant_step2_screen.dart`:

Replace `import '../models/reward_tier.dart';` with:

```dart
import '../models/program_tier.dart';
import '../../merchant/widgets/tier_editor_form.dart';
```

Remove the per-controller state (`_goalCtrls`, `_descCtrls`, `_validityCtrls` and their `_initRewardControllers`/`_addRewardController`/`_clearRewardControllers`/`_addNewTier`/`_removeTier` methods) — replace with:

```dart
  final _tierEditorKey = GlobalKey<TierEditorFormState>();
```

In `initState()`, remove the `_initRewardControllers(state.rewards);` call (no longer needed — `TierEditorForm` takes `initialTiers` directly).

In `dispose()`, remove the controller-disposal calls that no longer exist.

Replace `_next()`'s reward-collection block:

```dart
    if (loyaltyMode != 'cashback') {
      final tiers = _tierEditorKey.currentState?.currentTiers() ??
          ref.read(onboardingNotifierProvider).tiers;
      notifier.setTiers(tiers);
    }
```

Replace the "Liste dynamique des récompenses" section in `build()` (the `if (state.loyaltyMode != 'cashback') [...]` block containing the manual `Row`/`ListView.separated`/`OutlinedButton.icon` for rewards) with:

```dart
                      if (state.loyaltyMode != 'cashback') ...[
                        TierEditorForm(
                          key: _tierEditorKey,
                          initialTiers: state.tiers,
                          goalUnit: goalUnit,
                          onChanged: (_) {},
                        ),
                        const SizedBox(height: Sp.md),
                        OutlinedButton.icon(
                          onPressed: () => _tierEditorKey.currentState?.addTier(),
                          style: OutlinedButton.styleFrom(
                            minimumSize: const Size.fromHeight(48),
                            foregroundColor: AppColors.merchant,
                            side: const BorderSide(color: AppColors.merchant),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                          ),
                          icon: const Icon(LucideIcons.plus, size: 18),
                          label: Text(
                            'Ajouter un palier',
                            style: AppTextStyles.bodyMd()
                                .copyWith(color: AppColors.merchant, fontWeight: FontWeight.bold),
                          ),
                        ),
                      ],
```

- [ ] **Step 2: Verify compilation across the whole repo**

Run: `cd /home/othnelio/fcm/Miva_Fid && grep -rn "RewardTier\|LoyaltyLevel" lib --include="*.dart"`
Expected: zero matches anywhere.

Run: `flutter analyze lib/features/onboarding lib/features/merchant lib/features/client`
Expected: no errors (warnings pre-existing elsewhere in the repo are out of scope).

- [ ] **Step 3: Commit**

```bash
git add lib/features/onboarding/screens/merchant_step2_screen.dart
git commit -m "feat: onboarding step2 utilise l'éditeur de paliers unifié"
```

---

### Task 12: `LoyaltyCard` (Flutter) expose les paliers + icônes

**Files:**
- Modify: `lib/features/client/models/loyalty_card.dart`
- Modify: `lib/features/client/wallet/widgets/card_face_content.dart:358-375`

**Interfaces:**
- Produces: `class CardTier { final int order; final int goal; final String? levelName; final String rewardDescription; final String icon; final String status; }`, `LoyaltyCard.tiers: List<CardTier>` (vide si mono-palier), populated by `fromApi`/`applyRealtimeUpdate`.

- [ ] **Step 1: Add `CardTier` and wire it**

In `lib/features/client/models/loyalty_card.dart`, add near the top (after the `VipTier` enum):

```dart
/// Un palier tel que renvoyé par l'API (`LoyaltyCard::tiers`, côté serveur) —
/// vide tant que le programme n'a qu'un seul palier configuré.
class CardTier {
  final int order;
  final int goal;
  final String? levelName;
  final String rewardDescription;
  final String icon;

  /// `reached`, `current` ou `upcoming`.
  final String status;

  const CardTier({
    required this.order,
    required this.goal,
    this.levelName,
    required this.rewardDescription,
    required this.icon,
    required this.status,
  });

  factory CardTier.fromJson(Map<String, dynamic> json) {
    return CardTier(
      order: (json['order'] as num?)?.toInt() ?? 0,
      goal: (json['goal'] as num?)?.toInt() ?? 0,
      levelName: json['level_name'] as String?,
      rewardDescription: json['reward_description'] as String? ?? '',
      icon: json['icon'] as String? ?? '⭐',
      status: json['status'] as String? ?? 'upcoming',
    );
  }
}

List<CardTier> _tiersFromApi(dynamic raw) {
  if (raw is! List) return const [];
  return raw
      .whereType<Map>()
      .map((m) => CardTier.fromJson(Map<String, dynamic>.from(m)))
      .toList();
}
```

Add the field to `LoyaltyCard`:

```dart
  /// Roadmap des paliers — vide si un seul palier configuré (pas de système
  /// de niveau affiché dans ce cas, voir `levelName`).
  final List<CardTier> tiers;
```

Add it to the constructor (`this.tiers = const [],`), to `fromApi()` (`tiers: _tiersFromApi(json['tiers']),`), to `copyWith()` (`List<CardTier>? tiers,` param + `tiers: tiers ?? this.tiers,`), and to `applyRealtimeUpdate()` (`tiers: payload['tiers'] != null ? _tiersFromApi(payload['tiers']) : null,`).

- [ ] **Step 2: Add the icon to `_levelRow()`**

In `lib/features/client/wallet/widgets/card_face_content.dart`, replace `_levelRow()` (lines 362-375):

```dart
  Widget _levelRow() {
    if (card.levelName == null) return const SizedBox.shrink();
    final currentTier = card.tiers.where((t) => t.status == 'reached' || t.status == 'current').lastOrNull;
    final icon = currentTier?.icon;
    return Padding(
      padding: EdgeInsets.only(top: compact ? 2 : 4),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Text(icon, style: const TextStyle(fontSize: 12)),
            const SizedBox(width: 4),
          ],
          Flexible(
            child: Text(
              card.isMaxLevel
                  ? '${card.levelName} · niveau maximum'
                  : '${card.levelName} · ${card.levelPercentToNext ?? 0}% vers le niveau suivant',
              style: AppTextStyles.monoSmall(color: subtextColor),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ),
    );
  }
```

(`lastOrNull` comes from `package:collection` — check `pubspec.yaml` already lists it, used elsewhere in the client feature; if the import isn't already present in this file, add `import 'package:collection/collection.dart';` at the top.)

- [ ] **Step 3: Verify**

Run: `cd /home/othnelio/fcm/Miva_Fid && flutter analyze lib/features/client/models/loyalty_card.dart lib/features/client/wallet/widgets/card_face_content.dart`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add lib/features/client/models/loyalty_card.dart lib/features/client/wallet/widgets/card_face_content.dart
git commit -m "feat: LoyaltyCard expose les paliers, icône de niveau sur la carte"
```

---

### Task 13: Roadmap des paliers sur l'écran détail carte client

**Files:**
- Modify: `lib/features/client/card_detail/card_detail_screen.dart`

**Interfaces:**
- Consumes: `card.tiers` (Task 12).
- Produces: `_TierRoadmap` widget, affiché uniquement si `card.tiers.length > 1`, entre `_MiddleCardWidget` et le titre "Récompenses" existant (qui reste inchangé, y compris `_showRewardDetailSheet` déjà implémenté).

- [ ] **Step 1: Insert the roadmap widget**

In `lib/features/client/card_detail/card_detail_screen.dart`, in `CardDetailScreen.build()`, after the `RepaintBoundary` block (ends around line 88) and before `const SizedBox(height: 20)` / `Text(t.rewardsTitle, ...)`, insert:

```dart
                    if (card.tiers.length > 1) ...[
                      const SizedBox(height: 20),
                      _TierRoadmap(tiers: card.tiers),
                    ],
```

Add the new widget after `_MiddleCardWidget` (near line 458):

```dart
/// Roadmap verticale des paliers — palier atteint, en cours, et à venir,
/// avec le niveau et la récompense de chacun. Affichée uniquement quand le
/// programme a 2+ paliers (un seul palier = pas de système de niveau, voir
/// `LoyaltyCard.levelName`).
class _TierRoadmap extends StatelessWidget {
  final List<CardTier> tiers;
  const _TierRoadmap({required this.tiers});

  @override
  Widget build(BuildContext context) {
    return AppCard(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (int i = 0; i < tiers.length; i++) ...[
            _TierRoadmapRow(tier: tiers[i]),
            if (i < tiers.length - 1)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 2, horizontal: 18),
                child: Container(width: 2, height: 16, color: AppColors.border),
              ),
          ],
        ],
      ),
    );
  }
}

class _TierRoadmapRow extends StatelessWidget {
  final CardTier tier;
  const _TierRoadmapRow({required this.tier});

  @override
  Widget build(BuildContext context) {
    final isReached = tier.status == 'reached';
    final isCurrent = tier.status == 'current';

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 36,
          height: 36,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: isReached
                ? AppColors.successTint
                : (isCurrent ? AppColors.primary.withValues(alpha: 0.12) : AppColors.surfaceMuted),
            border: isCurrent ? Border.all(color: AppColors.primary, width: 1.5) : null,
          ),
          child: Text(tier.icon, style: const TextStyle(fontSize: 16)),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  tier.levelName ?? 'Palier',
                  style: AppTextStyles.titleMedium().copyWith(
                    fontSize: 14,
                    color: isReached || isCurrent ? AppColors.ink : AppColors.inkMuted(opacity: 0.6),
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  tier.rewardDescription,
                  style: AppTextStyles.bodySmall(color: AppColors.inkMuted(opacity: 0.7)),
                ),
              ],
            ),
          ),
        ),
        if (isReached)
          const Icon(LucideIcons.circleCheckBig, size: 18, color: AppColors.success),
      ],
    );
  }
}
```

- [ ] **Step 2: Verify**

Run: `cd /home/othnelio/fcm/Miva_Fid && flutter analyze lib/features/client/card_detail/card_detail_screen.dart`
Expected: no errors.

- [ ] **Step 3: Manual check**

Start the app (`flutter run`), open a client wallet card whose merchant program has 2+ tiers configured (create one via the merchant onboarding/settings screen from Task 10/11 if needed), open its detail screen, and confirm the roadmap renders above "Récompenses" with correct reached/current/upcoming states and icons. Confirm a mono-tier card shows no roadmap (unchanged screen).

- [ ] **Step 4: Commit**

```bash
git add lib/features/client/card_detail/card_detail_screen.dart
git commit -m "feat: roadmap des paliers sur l'écran détail carte client"
```

---

## Execution note

Tasks 1-7 (backend) are strictly ordered (each depends on the previous). Tasks 8-13 (frontend) depend on the backend API shape from Tasks 6-7 (specifically `tiers[]` in the program-creation payload and `config.tiers` in the profile response) but can be developed against a local backend running those tasks. Within the frontend, Task 8 must land before 9-13; 9 must land before 10-11; 12 must land before 13.
