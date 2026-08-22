# Équipe marchand — rôles Administrateur/Opérateur Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construire un système d'équipe marchand à deux rôles stricts (Administrateur/Opérateur), en réutilisant le schéma déjà migré (`staff_users`, colonnes d'attribution dormantes sur `loyalty_transactions`/`loyalty_rewards`) sans casser aucun endpoint existant.

**Architecture:** Un `StaffUser` ne s'authentifie jamais via son propre guard Sanctum — le token émis à la connexion appartient au `Restaurant` parent, avec l'identité de l'opérateur encodée dans les `abilities` du token (`staff:{id}`). `$request->user()` reste donc le `Restaurant` sur 100% des routes existantes. Un helper `CurrentActor::resolve()` décode l'acteur réel (Restaurant=admin, ou StaffUser+son rôle) à partir du token, utilisé par un middleware `admin.only` et aux quelques points d'écriture qui doivent tracer "qui a fait l'opération".

**Tech Stack:** Laravel 13 (Sanctum personal access tokens, abilities), PostgreSQL (contrainte CHECK), Flutter/Riverpod.

**Spec:** `docs/superpowers/specs/2026-08-21-equipe-roles-admin-operateur-design.md`

## Global Constraints

- Deux rôles exactement : `admin`, `operator`. Jamais un troisième.
- Ne jamais modifier les règles métier des programmes de fidélité (calcul tampons/points/cashback, paliers) — uniquement rôles/permissions/navigation.
- Aucun endpoint existant ne doit changer de comportement pour un compte `Restaurant` classique (admin implicite).
- Messages d'erreur simples, non techniques, en français, cohérents avec le reste de l'app.
- Chaque tâche backend se termine par `php artisan test` vert (suite complète, pas seulement le nouveau fichier).
- Chaque tâche frontend se termine par `flutter analyze` clean et `flutter test` vert sur les fichiers concernés.

---

## File Structure

**Backend (`restaurant-loyalty-api`) :**
- `database/migrations/2026_08_21_130000_constrain_staff_users_role.php` (nouveau) — contrainte CHECK `role IN ('admin','operator')`.
- `app/Models/StaffUser.php` (nouveau) — modèle, relation `restaurant()`.
- `app/Support/CurrentActor.php` (nouveau) — résolution de l'acteur courant depuis le token.
- `app/Support/StaffUserInactiveException.php` (nouveau) — exception auto-rendue 401.
- `app/Support/RestaurantPayload.php` (nouveau) — extrait le corps de `RestaurantAuthController::restaurantData()`, réutilisé par le login staff.
- `app/Http/Middleware/EnsureAdmin.php` (nouveau) — middleware `admin.only`.
- `app/Http/Controllers/Api/StaffAuthController.php` (nouveau) — `POST /auth/merchant/staff/login`.
- `app/Http/Controllers/Api/TeamController.php` (nouveau) — CRUD équipe.
- `app/Http/Controllers/Api/RestaurantAuthController.php` (modifié) — `restaurantData()` délègue à `RestaurantPayload`, ajoute `actor`, `verifyPassword`/`changePassword` deviennent conscients de l'acteur.
- `app/Http/Controllers/Api/MerchantDashboardController.php` (modifié) — attribution `staff_user_id`/`used_by_staff_user_id`/`canceled_by_staff_user_id`, nouvel endpoint historique.
- `bootstrap/app.php` (modifié) — alias middleware `admin.only`.
- `routes/api.php` (modifié) — nouvelle route staff login, routes équipe, `admin.only` sur les routes de configuration, route historique marchand.

**Frontend (`Miva_Fid`) :**
- `lib/features/merchant/models/restaurant_account.dart` (modifié) — `actorType`/`staffName`/`staffRole`.
- `lib/features/merchant/providers/merchant_auth_provider.dart` (modifié) — `staffLogin()`, `isAdminProvider`.
- `lib/core/api/services/merchant_auth_service.dart` + `merchant_auth_repository.dart` (modifiés) — appel staff login.
- `lib/features/onboarding/screens/merchant_auth_screen.dart` (modifié) — bascule Administrateur/Opérateur.
- `lib/features/merchant/screens/merchant_shell.dart` (modifié) — navigation réduite pour un opérateur.
- `lib/core/router/app_router.dart` (modifié) — redirection opérateur vers `/merchant/validate`.
- `lib/features/merchant/screens/team_screen.dart` (réécrit) — vrai CRUD équipe.
- `lib/features/merchant/screens/client_detail_screen.dart` (modifié) — historique câblé.

---

### Task 1: Migration + modèle `StaffUser`

**Files:**
- Create: `database/migrations/2026_08_21_130000_constrain_staff_users_role.php`
- Create: `app/Models/StaffUser.php`
- Test: `tests/Unit/Models/StaffUserTest.php`

**Interfaces:**
- Produces: `StaffUser` (fillable: `restaurant_id`, `name`, `email`, `phone`, `password`, `role`, `is_active` ; casts: `password` → `hashed`, `is_active` → `boolean` ; relation `restaurant(): BelongsTo`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\Restaurant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_is_hashed_automatically(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('secret123'),
        ]);

        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Jean',
            'email'         => 'jean@example.com',
            'password'      => 'plainpassword',
            'role'          => 'operator',
        ]);

        $this->assertTrue(Hash::check('plainpassword', $staff->password));
    }

    public function test_is_active_defaults_to_true(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce2@example.com', 'password' => bcrypt('secret123'),
        ]);

        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Jean',
            'email'         => 'jean2@example.com',
            'password'      => 'plainpassword',
            'role'          => 'operator',
        ]);

        $this->assertTrue($staff->fresh()->is_active);
    }

    public function test_role_must_be_admin_or_operator(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce3@example.com', 'password' => bcrypt('secret123'),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        StaffUser::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Jean',
            'email'         => 'jean3@example.com',
            'password'      => 'plainpassword',
            'role'          => 'manager',
        ]);
    }

    public function test_belongs_to_restaurant(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce4@example.com', 'password' => bcrypt('secret123'),
        ]);

        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Jean',
            'email'         => 'jean4@example.com',
            'password'      => 'plainpassword',
            'role'          => 'operator',
        ]);

        $this->assertTrue($staff->restaurant->is($restaurant));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StaffUserTest`
Expected: FAIL — `Class "App\Models\StaffUser" not found`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deux rôles exactement (le commentaire de colonne d'origine "owner,
 * manager, staff" décrivait un système à 3 niveaux jamais implémenté —
 * voir docs/superpowers/specs/2026-08-21-equipe-roles-admin-operateur-design.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE staff_users DROP CONSTRAINT IF EXISTS staff_users_role_check");
        DB::statement("ALTER TABLE staff_users ADD CONSTRAINT staff_users_role_check CHECK (role IN ('admin', 'operator'))");
        DB::statement("ALTER TABLE staff_users ALTER COLUMN role SET DEFAULT 'operator'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE staff_users DROP CONSTRAINT IF EXISTS staff_users_role_check");
        DB::statement("ALTER TABLE staff_users ALTER COLUMN role SET DEFAULT 'staff'");
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffUser extends Model
{
    protected $fillable = [
        'restaurant_id',
        'name',
        'email',
        'phone',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password'  => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
```

- [ ] **Step 5: Run migration and test**

Run: `php artisan migrate && php artisan test --filter=StaffUserTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_21_130000_constrain_staff_users_role.php app/Models/StaffUser.php tests/Unit/Models/StaffUserTest.php
git commit -m "feat: modèle StaffUser + contrainte rôle admin/operator"
```

---

### Task 2: `CurrentActor` — résolution de l'acteur courant

**Files:**
- Create: `app/Support/StaffUserInactiveException.php`
- Create: `app/Support/CurrentActor.php`
- Test: `tests/Unit/Support/CurrentActorTest.php`

**Interfaces:**
- Consumes: `StaffUser` (Task 1).
- Produces: `CurrentActor::resolve(Request $request): CurrentActor` — propriétés publiques `type` (`'restaurant'|'staff'`), `staffUser` (`?StaffUser`), `role` (`'admin'|'operator'`) ; méthode `isAdmin(): bool` ; méthode `toArray(): array` (`type`, `id`, `name`, `role`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Support;

use App\Models\Restaurant;
use App\Models\StaffUser;
use App\Support\CurrentActor;
use App\Support\StaffUserInactiveException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CurrentActorTest extends TestCase
{
    use RefreshDatabase;

    private function restaurant(): Restaurant
    {
        return Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('secret123'),
        ]);
    }

    public function test_plain_restaurant_token_resolves_to_admin(): void
    {
        $restaurant = $this->restaurant();
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/merchant/me');

        $response->assertOk();
        // La résolution elle-même est testée via un accès direct au modèle.
        $request = Request::create('/');
        $request->setUserResolver(fn () => $restaurant);
        $sanctumToken = $restaurant->tokens()->first();
        $restaurant->withAccessToken($sanctumToken);
        $request->setUserResolver(fn () => $restaurant);

        $actor = CurrentActor::resolve($request);
        $this->assertSame('restaurant', $actor->type);
        $this->assertSame('admin', $actor->role);
        $this->assertTrue($actor->isAdmin());
        $this->assertNull($actor->staffUser);
    }

    public function test_staff_ability_token_resolves_to_that_staff_user(): void
    {
        $restaurant = $this->restaurant();
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => 'secret', 'role' => 'operator',
        ]);
        $token = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->accessToken;

        $request = Request::create('/');
        $restaurant->withAccessToken($token);
        $request->setUserResolver(fn () => $restaurant);

        $actor = CurrentActor::resolve($request);
        $this->assertSame('staff', $actor->type);
        $this->assertSame('operator', $actor->role);
        $this->assertFalse($actor->isAdmin());
        $this->assertTrue($actor->staffUser->is($staff));
    }

    public function test_staff_admin_role_is_admin(): void
    {
        $restaurant = $this->restaurant();
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Awa',
            'email' => 'awa@example.com', 'password' => 'secret', 'role' => 'admin',
        ]);
        $token = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->accessToken;

        $request = Request::create('/');
        $restaurant->withAccessToken($token);
        $request->setUserResolver(fn () => $restaurant);

        $this->assertTrue(CurrentActor::resolve($request)->isAdmin());
    }

    public function test_inactive_staff_user_throws(): void
    {
        $restaurant = $this->restaurant();
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean2@example.com', 'password' => 'secret',
            'role' => 'operator', 'is_active' => false,
        ]);
        $token = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->accessToken;

        $request = Request::create('/');
        $restaurant->withAccessToken($token);
        $request->setUserResolver(fn () => $restaurant);

        $this->expectException(StaffUserInactiveException::class);
        CurrentActor::resolve($request);
    }

    public function test_no_authenticated_user_defaults_to_admin(): void
    {
        $request = Request::create('/');
        $actor = CurrentActor::resolve($request);

        $this->assertSame('restaurant', $actor->type);
        $this->assertSame('admin', $actor->role);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CurrentActorTest`
Expected: FAIL — `Class "App\Support\CurrentActor" not found`.

- [ ] **Step 3: Write `StaffUserInactiveException`**

```php
<?php

namespace App\Support;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StaffUserInactiveException extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Ce compte a été désactivé. Contactez votre administrateur.',
        ], 401);
    }
}
```

- [ ] **Step 4: Write `CurrentActor`**

```php
<?php

namespace App\Support;

use App\Models\StaffUser;
use Illuminate\Http\Request;

final class CurrentActor
{
    private function __construct(
        public readonly string $type,
        public readonly ?StaffUser $staffUser,
        public readonly string $role,
    ) {
    }

    public static function resolve(Request $request): self
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $abilities = $token?->abilities ?? [];

        foreach ($abilities as $ability) {
            if (str_starts_with($ability, 'staff:')) {
                $staffId = (int) substr($ability, strlen('staff:'));
                $staffUser = StaffUser::find($staffId);

                if ($staffUser === null || ! $staffUser->is_active) {
                    throw new StaffUserInactiveException();
                }

                return new self('staff', $staffUser, $staffUser->role);
            }
        }

        return new self('restaurant', null, 'admin');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id'   => $this->staffUser?->id,
            'name' => $this->staffUser?->name,
            'role' => $this->role,
        ];
    }
}
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter=CurrentActorTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Support/CurrentActor.php app/Support/StaffUserInactiveException.php tests/Unit/Support/CurrentActorTest.php
git commit -m "feat: CurrentActor résout l'acteur (admin/staff) depuis le token"
```

---

### Task 3: `RestaurantPayload` + réponses conscientes de l'acteur

**Files:**
- Create: `app/Support/RestaurantPayload.php`
- Modify: `app/Http/Controllers/Api/RestaurantAuthController.php:435-483` (méthode `restaurantData`), et les 9 sites d'appel (lignes 60, 109, 155, 180, 214, 231, 268, 285, 404 — numéros avant modification).
- Test: `tests/Feature/Merchant/ActorInResponseTest.php`

**Interfaces:**
- Consumes: `CurrentActor` (Task 2).
- Produces: `RestaurantPayload::build(\App\Models\Restaurant $restaurant): array` — exactement le tableau actuellement renvoyé par `restaurantData()` (id...created_at, notification_preferences), sans la clé `actor`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Merchant;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActorInResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_includes_admin_actor_for_a_plain_restaurant_account(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('secret123'),
        ]);
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/merchant/me');

        $response->assertOk();
        $response->assertJsonPath('restaurant.actor.type', 'restaurant');
        $response->assertJsonPath('restaurant.actor.role', 'admin');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ActorInResponseTest`
Expected: FAIL — pas de clé `actor` dans la réponse.

- [ ] **Step 3: Read the current `restaurantData()` body**

Le corps actuel (lignes 435-482 de `RestaurantAuthController.php`) doit être déplacé tel quel dans `RestaurantPayload::build()`. Lire le fichier avant d'écrire l'étape suivante pour copier le contenu exact (ne pas le reconstruire de mémoire — inclut la config du programme de fidélité, les paliers, `notification_preferences`).

- [ ] **Step 4: Create `RestaurantPayload`**

```php
<?php

namespace App\Support;

use App\Models\Restaurant;

final class RestaurantPayload
{
    private const DEFAULT_NOTIFICATION_PREFERENCES = [
        'new_client'    => true,
        'reward'        => true,
        'low_sms'       => true,
        'weekly_report' => false,
        'promotions'    => false,
    ];

    public static function build(Restaurant $restaurant): array
    {
        return [
            'id'                => $restaurant->id,
            'uuid'              => $restaurant->uuid,
            'name'              => $restaurant->name,
            'category'          => $restaurant->category,
            'email'             => $restaurant->email,
            'phone'             => $restaurant->phone,
            'address'           => $restaurant->address,
            'city'              => $restaurant->city,
            'country'           => $restaurant->country,
            'description'       => $restaurant->description,
            'logo_url'          => $restaurant->logo_url,
            'whatsapp'          => $restaurant->whatsapp,
            'instagram'         => $restaurant->instagram,
            'facebook'          => $restaurant->facebook,
            'tiktok'            => $restaurant->tiktok,
            'qr_token'          => $restaurant->qr_token,
            'short_code'        => $restaurant->short_code,
            'has_business_info'   => $restaurant->hasBusinessInfo(),
            'latitude'            => $restaurant->location?->latitude,
            'longitude'           => $restaurant->location?->longitude,
            'has_location'        => $restaurant->hasLocation(),
            'has_loyalty_program' => $restaurant->hasLoyaltyProgram(),
            'loyalty_program'     => $restaurant->loyaltyProgram
                ? [
                    'type'   => $restaurant->loyaltyProgram->type,
                    'config' => [
                        ...$restaurant->loyaltyProgram->config ?? [],
                        'loops' => $restaurant->loyaltyProgram->loops,
                        'tiers' => $restaurant->loyaltyProgram->tiers->map(fn ($t) => [
                            'goal'                => $t->goal,
                            'level_name'          => $t->level_name,
                            'reward_description'  => $t->reward_description,
                            'reveal_reward'       => $t->reveal_reward,
                            'validity_days'       => $t->validity_days,
                        ])->all(),
                    ],
                ]
                : null,
            'plan'                => $restaurant->planSlug(),
            'sms_credits'         => (int) $restaurant->sms_credits,
            'notification_preferences' => [
                ...self::DEFAULT_NOTIFICATION_PREFERENCES,
                ...$restaurant->notification_preferences ?? [],
            ],
            'created_at'        => $restaurant->created_at?->toIso8601String(),
        ];
    }
}
```

**IMPORTANT** : si le contenu réel de `restaurantData()` (lu à l'étape 3) diffère de ce qui précède (le fichier a pu changer depuis la rédaction de ce plan), copier le contenu réel — ce bloc doit être un miroir exact, pas une reconstruction approximative.

- [ ] **Step 5: Replace `restaurantData()` in `RestaurantAuthController` to delegate + add actor**

Remplacer (lignes ~435-482, signature et corps) :

```php
    private function restaurantData(Restaurant $restaurant): array
    {
        return [
            // ... corps actuel ...
        ];
    }
```

par :

```php
    private function restaurantData(Restaurant $restaurant, Request $request): array
    {
        return [
            ...\App\Support\RestaurantPayload::build($restaurant),
            'actor' => \App\Support\CurrentActor::resolve($request)->toArray(),
        ];
    }
```

- [ ] **Step 6: Update all 9 call sites to pass `$request`**

Chaque site actuel `$this->restaurantData($restaurant)` ou `$this->restaurantData($restaurant->fresh())` devient `$this->restaurantData($restaurant, $request)` / `$this->restaurantData($restaurant->fresh(), $request)`. Localiser chaque occurrence avec :

```bash
grep -n "restaurantData(" app/Http/Controllers/Api/RestaurantAuthController.php
```

Chaque méthode contenant un appel a déjà `Request $request` dans sa signature (vérifié : `register`, `login`, `socialLogin`, `updateBusinessInfo`, `uploadLogo`, `deleteLogo`, `updatePlan`, `me`, `completeSocialProfile`/équivalent). Ajouter `, $request` avant la parenthèse fermante de chaque appel.

- [ ] **Step 7: Run tests**

Run: `php artisan test`
Expected: PASS — la suite complète (y compris tous les tests `RestaurantAuthController`/`RestaurantChangePasswordTest`/`LoyaltyProgramCreationTest` existants) reste verte, `ActorInResponseTest` passe.

- [ ] **Step 8: Commit**

```bash
git add app/Support/RestaurantPayload.php app/Http/Controllers/Api/RestaurantAuthController.php tests/Feature/Merchant/ActorInResponseTest.php
git commit -m "refactor: extrait RestaurantPayload, ajoute 'actor' aux réponses marchand"
```

---

### Task 4: Middleware `admin.only` + application aux routes de configuration

**Files:**
- Create: `app/Http/Middleware/EnsureAdmin.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/api.php` (ajout `->middleware('admin.only')`)
- Test: `tests/Feature/Merchant/AdminOnlyMiddlewareTest.php`

**Interfaces:**
- Consumes: `CurrentActor` (Task 2).
- Produces: alias de middleware `admin.only`, utilisable sur n'importe quelle route via `->middleware('admin.only')`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Merchant;

use App\Models\Restaurant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOnlyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private function restaurant(): Restaurant
    {
        return Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('secret123'),
        ]);
    }

    private function operatorToken(Restaurant $restaurant): string
    {
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => 'secret', 'role' => 'operator',
        ]);

        return $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;
    }

    public function test_operator_cannot_update_business_profile(): void
    {
        $restaurant = $this->restaurant();
        $token = $this->operatorToken($restaurant);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/profile', [
                'name' => 'Nouveau nom', 'category' => 'Café', 'phone' => '+22890000099',
            ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Réservé à l\'administrateur.']);
    }

    public function test_operator_cannot_list_clients(): void
    {
        $restaurant = $this->restaurant();
        $token = $this->operatorToken($restaurant);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients')
            ->assertStatus(403);
    }

    public function test_operator_cannot_view_stats(): void
    {
        $restaurant = $this->restaurant();
        $token = $this->operatorToken($restaurant);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/stats')
            ->assertStatus(403);
    }

    public function test_operator_cannot_create_loyalty_program(): void
    {
        $restaurant = $this->restaurant();
        $token = $this->operatorToken($restaurant);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-programs', ['mode' => 'stamps'])
            ->assertStatus(403);
    }

    public function test_operator_cannot_manage_team(): void
    {
        $restaurant = $this->restaurant();
        $token = $this->operatorToken($restaurant);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/merchant/team')
            ->assertStatus(403);
    }

    public function test_operator_can_still_look_up_a_client_card(): void
    {
        $restaurant = $this->restaurant();
        $token = $this->operatorToken($restaurant);

        // Pas de carte à trouver, mais la requête doit passer le middleware
        // (404 métier, pas 403 permission).
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients/lookup?code=UNKNOWN');

        $response->assertStatus(404);
    }

    public function test_plain_admin_account_is_unaffected(): void
    {
        $restaurant = $this->restaurant();
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/stats')
            ->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminOnlyMiddlewareTest`
Expected: FAIL — toutes les routes actuellement ouvertes renvoient 200/autre au lieu de 403, `admin.only` n'existe pas encore.

- [ ] **Step 3: Write the middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Support\CurrentActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! CurrentActor::resolve($request)->isAdmin()) {
            return response()->json([
                'message' => 'Réservé à l\'administrateur.',
            ], 403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the alias**

Modifier `bootstrap/app.php` :

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.only' => \App\Http\Middleware\EnsureAdmin::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'api/webhooks/fedapay',
        ]);
    })
```

- [ ] **Step 5: Apply the middleware in `routes/api.php`**

Modifier le groupe `auth/merchant` protégé (lignes ~65-75) :

```php
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me',        [RestaurantAuthController::class, 'me']);
        Route::put('/profile',   [RestaurantAuthController::class, 'updateBusinessInfo'])->middleware('admin.only');
        Route::post('/profile/logo',   [RestaurantAuthController::class, 'uploadLogo'])->middleware(['throttle:10,1', 'admin.only']);
        Route::delete('/profile/logo', [RestaurantAuthController::class, 'deleteLogo'])->middleware('admin.only');
        Route::put('/plan',      [RestaurantAuthController::class, 'updatePlan'])->middleware('admin.only');
        Route::post('/verify-password', [RestaurantAuthController::class, 'verifyPassword']);
        Route::put('/change-password',  [RestaurantAuthController::class, 'changePassword']);
        Route::put('/notification-preferences', [RestaurantAuthController::class, 'updateNotificationPreferences'])->middleware('admin.only');
        Route::post('/logout',   [RestaurantAuthController::class, 'logout']);
    });
```

Modifier la route programme (ligne ~79) :

```php
Route::middleware(['auth:sanctum', 'admin.only'])->post('/loyalty-programs', [LoyaltyProgramController::class, 'store']);
```

Modifier le groupe `merchant` (lignes ~82-97) — seules `stats`, `clients` (liste exacte) et `campaigns*` passent sous `admin.only`, le reste (scan/lookup/carte/tampon/cashback/récompenses) reste ouvert aux deux rôles :

```php
Route::middleware('auth:sanctum')->prefix('merchant')->group(function () {
    Route::get('/stats',                    [MerchantDashboardController::class, 'stats'])->middleware('admin.only');
    Route::get('/clients',                  [MerchantDashboardController::class, 'clients'])->middleware('admin.only');
    Route::get('/clients/lookup',           [MerchantDashboardController::class, 'lookup']);
    Route::get('/clients/{loyaltyCard}',    [MerchantDashboardController::class, 'showClient']);
    Route::get('/clients/{loyaltyCard}/history', [MerchantDashboardController::class, 'clientHistory']);
    Route::post('/clients/{loyaltyCard}/stamps', [MerchantDashboardController::class, 'addStamp']);
    Route::post('/clients/{loyaltyCard}/redeem-cashback', [MerchantDashboardController::class, 'redeemCashback']);

    Route::get('/rewards/lookup',              [MerchantDashboardController::class, 'lookupReward']);
    Route::post('/rewards/{loyaltyReward}/redeem', [MerchantDashboardController::class, 'redeemReward']);
    Route::post('/rewards/{loyaltyReward}/cancel', [MerchantDashboardController::class, 'cancelReward']);

    Route::get('/campaigns',            [MerchantCampaignController::class, 'index'])->middleware('admin.only');
    Route::get('/campaigns/recipients', [MerchantCampaignController::class, 'recipients'])->middleware('admin.only');
    Route::post('/campaigns',           [MerchantCampaignController::class, 'store'])->middleware('admin.only');
});
```

(La route `/clients/{loyaltyCard}/history` référence `clientHistory`, pas encore créée — Task 9. Ne pas exécuter les tests tant que Task 9 n'est pas faite ferait échouer *cette* route spécifiquement ; les tests de cette tâche ne la ciblent pas, donc ce n'est pas bloquant, mais laisser un commentaire n'est pas nécessaire — la route existera avant la fin du plan.)

- [ ] **Step 6: Run tests**

Run: `php artisan test`
Expected: PASS — `AdminOnlyMiddlewareTest` (7 tests) passe, et toute la suite existante (tests marchand qui utilisent un token `Restaurant` classique) reste verte puisque `CurrentActor::resolve` retourne `admin` par défaut pour ces tokens.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/EnsureAdmin.php bootstrap/app.php routes/api.php tests/Feature/Merchant/AdminOnlyMiddlewareTest.php
git commit -m "feat: middleware admin.only sur les routes de configuration marchand"
```

---

### Task 5: Connexion opérateur (`StaffAuthController`)

**Files:**
- Create: `app/Http/Controllers/Api/StaffAuthController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Merchant/StaffLoginTest.php`

**Interfaces:**
- Consumes: `RestaurantPayload::build()` (Task 3), `StaffUser` (Task 1).
- Produces: `POST /api/auth/merchant/staff/login` → `{access_token, token_type, restaurant: {...}, actor: {type: 'staff', id, name, role}}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Merchant;

use App\Models\Restaurant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffLoginTest extends TestCase
{
    use RefreshDatabase;

    private function restaurantWithOperator(bool $active = true): array
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('secret123'),
        ]);
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => 'operatorpass',
            'role' => 'operator', 'is_active' => $active,
        ]);

        return [$restaurant, $staff];
    }

    public function test_operator_can_log_in_with_correct_credentials(): void
    {
        [$restaurant, $staff] = $this->restaurantWithOperator();

        $response = $this->postJson('/api/auth/merchant/staff/login', [
            'email' => 'jean@example.com', 'password' => 'operatorpass',
        ]);

        $response->assertOk();
        $response->assertJsonPath('actor.type', 'staff');
        $response->assertJsonPath('actor.role', 'operator');
        $response->assertJsonPath('actor.name', 'Jean');
        $response->assertJsonPath('restaurant.name', 'Chez Awa');
        $this->assertNotEmpty($response->json('access_token'));
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->restaurantWithOperator();

        $response = $this->postJson('/api/auth/merchant/staff/login', [
            'email' => 'jean@example.com', 'password' => 'wrong',
        ]);

        $response->assertStatus(401);
        $response->assertJsonMissing(['errors']);
    }

    public function test_unknown_email_is_rejected(): void
    {
        $response = $this->postJson('/api/auth/merchant/staff/login', [
            'email' => 'inconnu@example.com', 'password' => 'whatever',
        ]);

        $response->assertStatus(401);
    }

    public function test_inactive_operator_cannot_log_in(): void
    {
        $this->restaurantWithOperator(active: false);

        $response = $this->postJson('/api/auth/merchant/staff/login', [
            'email' => 'jean@example.com', 'password' => 'operatorpass',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Ce compte a été désactivé. Contactez votre administrateur.']);
    }

    public function test_issued_token_lets_the_operator_use_operational_routes(): void
    {
        [$restaurant, $staff] = $this->restaurantWithOperator();

        $login = $this->postJson('/api/auth/merchant/staff/login', [
            'email' => 'jean@example.com', 'password' => 'operatorpass',
        ]);
        $token = $login->json('access_token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/merchant/me')
            ->assertOk();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StaffLoginTest`
Expected: FAIL — route inexistante (404).

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffUser;
use App\Support\RestaurantPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffAuthController extends Controller
{
    /**
     * POST /api/auth/merchant/staff/login
     *
     * Le token émis appartient au `Restaurant` parent (pas au `StaffUser`) :
     * voir CurrentActor / docs/superpowers/specs/2026-08-21-equipe-roles-admin-operateur-design.md.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $staffUser = StaffUser::where('email', $request->email)->first();

        if (! $staffUser || ! Hash::check($request->password, $staffUser->password)) {
            return response()->json([
                'message' => 'Identifiants incorrects.',
            ], 401);
        }

        if (! $staffUser->is_active) {
            return response()->json([
                'message' => 'Ce compte a été désactivé. Contactez votre administrateur.',
            ], 401);
        }

        $restaurant = $staffUser->restaurant;
        $token = $restaurant->createToken(
            "staff:{$staffUser->id}",
            ["staff:{$staffUser->id}"],
        )->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'restaurant'   => RestaurantPayload::build($restaurant),
            'actor'        => [
                'type' => 'staff',
                'id'   => $staffUser->id,
                'name' => $staffUser->name,
                'role' => $staffUser->role,
            ],
        ]);
    }
}
```

- [ ] **Step 4: Add the route**

Dans `routes/api.php`, à côté des autres routes publiques `auth/merchant` (après la ligne `Route::post('/social', ...)`, ligne ~57) :

```php
    Route::post('/staff/login', [StaffAuthController::class, 'login'])->middleware('throttle:5,1');
```

Ajouter l'import en haut du fichier, à côté des autres `use App\Http\Controllers\Api\...` :

```php
use App\Http\Controllers\Api\StaffAuthController;
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter=StaffLoginTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/StaffAuthController.php routes/api.php tests/Feature/Merchant/StaffLoginTest.php
git commit -m "feat: connexion opérateur (POST /auth/merchant/staff/login)"
```

---

### Task 6: Changement de mot de passe conscient de l'acteur

**Files:**
- Modify: `app/Http/Controllers/Api/RestaurantAuthController.php` (`verifyPassword`, `changePassword`)
- Test: `tests/Feature/Merchant/StaffChangePasswordTest.php`

**Interfaces:**
- Consumes: `CurrentActor` (Task 2).

**Contexte** : ces deux endpoints (déjà existants, session précédente) opèrent aujourd'hui toujours sur `$request->user()->password` — qui reste le `Restaurant`, même pour un opérateur connecté (voir Task 3/architecture). Sans correction, un opérateur qui "change son mot de passe" changerait en réalité celui du compte administrateur. Il faut les rendre conscients de l'acteur : s'il s'agit d'un `StaffUser`, agir sur `staffUser->password`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Merchant;

use App\Models\Restaurant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_changing_password_does_not_touch_the_restaurant_password(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('adminpass123'),
        ]);
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => 'oldoperatorpass', 'role' => 'operator',
        ]);
        $token = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/change-password', [
                'current_password'      => 'oldoperatorpass',
                'password'              => 'newoperatorpass',
                'password_confirmation' => 'newoperatorpass',
            ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('newoperatorpass', $staff->fresh()->password));
        $this->assertTrue(Hash::check('adminpass123', $restaurant->fresh()->password));
    }

    public function test_operator_verify_password_checks_the_staff_password_not_the_restaurant_one(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce2@example.com', 'password' => bcrypt('adminpass123'),
        ]);
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean2@example.com', 'password' => 'operatorpass', 'role' => 'operator',
        ]);
        $token = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;

        // Le mot de passe ADMIN ne doit pas être accepté pour l'opérateur.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/merchant/verify-password', ['current_password' => 'adminpass123'])
            ->assertStatus(422);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/merchant/verify-password', ['current_password' => 'operatorpass'])
            ->assertOk();
    }

    public function test_admin_change_password_still_works_unchanged(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce3@example.com', 'password' => bcrypt('oldpass123'),
        ]);
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/change-password', [
                'current_password'      => 'oldpass123',
                'password'              => 'newpass456',
                'password_confirmation' => 'newpass456',
            ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('newpass456', $restaurant->fresh()->password));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StaffChangePasswordTest`
Expected: FAIL — les deux premiers tests échouent (le mot de passe du `Restaurant` est modifié/vérifié au lieu de celui du `StaffUser`).

- [ ] **Step 3: Modify `verifyPassword`**

Remplacer le corps actuel de `verifyPassword` dans `RestaurantAuthController.php` par :

```php
    public function verifyPassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
        ]);

        $actor = \App\Support\CurrentActor::resolve($request);
        $hashed = $actor->staffUser?->password ?? $request->user()->password;

        if (! Hash::check($request->current_password, $hashed)) {
            return response()->json([
                'message' => 'Le mot de passe est incorrect.',
                'valid'   => false,
            ], 422);
        }

        return response()->json([
            'message' => 'Mot de passe vérifié.',
            'valid'   => true,
        ]);
    }
```

- [ ] **Step 4: Modify `changePassword`**

```php
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $actor = \App\Support\CurrentActor::resolve($request);
        $target = $actor->staffUser ?? $request->user();

        if (! Hash::check($request->current_password, $target->password)) {
            return response()->json([
                'message' => 'Le mot de passe actuel est incorrect.',
            ], 422);
        }

        if (Hash::check($request->password, $target->password)) {
            return response()->json([
                'message' => 'Le nouveau mot de passe doit être différent de l\'actuel.',
            ], 422);
        }

        $target->update(['password' => Hash::make($request->password)]);

        return response()->json([
            'message' => 'Votre mot de passe a été modifié avec succès.',
        ]);
    }
```

- [ ] **Step 5: Run tests**

Run: `php artisan test`
Expected: PASS — les 3 nouveaux tests, et `RestaurantChangePasswordTest` (existant, admin) reste vert à l'identique.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/RestaurantAuthController.php tests/Feature/Merchant/StaffChangePasswordTest.php
git commit -m "fix: verify/change-password agit sur le bon compte (admin ou opérateur)"
```

---

### Task 7: Gestion d'équipe (`TeamController`)

**Files:**
- Create: `app/Http/Controllers/Api/TeamController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Merchant/TeamManagementTest.php`

**Interfaces:**
- Consumes: `StaffUser`, `CurrentActor` (Tasks 1-2).
- Produces: `GET/POST /auth/merchant/team`, `PUT /auth/merchant/team/{staffUser}`, `PATCH /auth/merchant/team/{staffUser}/toggle-active`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Merchant;

use App\Models\Restaurant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): array
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('secret123'),
        ]);

        return [$restaurant, $restaurant->createToken('merchant-app')->plainTextToken];
    }

    public function test_admin_can_invite_an_operator(): void
    {
        [$restaurant, $token] = $this->adminToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/merchant/team', [
                'name' => 'Jean', 'email' => 'jean@example.com',
                'phone' => '+22890000001', 'password' => 'operatorpass', 'role' => 'operator',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('staff_users', [
            'restaurant_id' => $restaurant->id, 'email' => 'jean@example.com', 'role' => 'operator',
        ]);
    }

    public function test_role_must_be_admin_or_operator(): void
    {
        [, $token] = $this->adminToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/merchant/team', [
                'name' => 'Jean', 'email' => 'jean@example.com',
                'password' => 'operatorpass', 'role' => 'manager',
            ]);

        $response->assertStatus(422);
    }

    public function test_duplicate_email_is_rejected_with_a_clear_message(): void
    {
        [$restaurant, $token] = $this->adminToken();
        StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => 'x', 'role' => 'operator',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/merchant/team', [
                'name' => 'Autre Jean', 'email' => 'jean@example.com',
                'password' => 'operatorpass', 'role' => 'operator',
            ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Cette adresse est déjà utilisée par un membre de l\'équipe.']);
    }

    public function test_admin_can_list_only_their_own_team(): void
    {
        [$restaurant, $token] = $this->adminToken();
        StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => 'x', 'role' => 'operator',
        ]);
        $otherRestaurant = Restaurant::create([
            'name' => 'Autre', 'category' => 'Restaurant',
            'email' => 'autre@example.com', 'password' => bcrypt('secret123'),
        ]);
        StaffUser::create([
            'restaurant_id' => $otherRestaurant->id, 'name' => 'Paul',
            'email' => 'paul@example.com', 'password' => 'x', 'role' => 'operator',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/merchant/team');

        $response->assertOk();
        $response->assertJsonCount(1, 'team');
        $response->assertJsonPath('team.0.name', 'Jean');
    }

    public function test_admin_can_edit_an_operator(): void
    {
        [$restaurant, $token] = $this->adminToken();
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => 'x', 'role' => 'operator',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/auth/merchant/team/{$staff->id}", ['name' => 'Jean Dupont']);

        $response->assertOk();
        $this->assertSame('Jean Dupont', $staff->fresh()->name);
    }

    public function test_admin_cannot_edit_another_restaurants_staff(): void
    {
        [, $token] = $this->adminToken();
        $otherRestaurant = Restaurant::create([
            'name' => 'Autre', 'category' => 'Restaurant',
            'email' => 'autre2@example.com', 'password' => bcrypt('secret123'),
        ]);
        $staff = StaffUser::create([
            'restaurant_id' => $otherRestaurant->id, 'name' => 'Paul',
            'email' => 'paul2@example.com', 'password' => 'x', 'role' => 'operator',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/auth/merchant/team/{$staff->id}", ['name' => 'Hacked'])
            ->assertStatus(404);
    }

    public function test_deactivating_an_operator_immediately_revokes_their_access(): void
    {
        [$restaurant, $adminToken] = $this->adminToken();
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => bcrypt('operatorpass'), 'role' => 'operator',
        ]);
        $operatorToken = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;

        // L'opérateur peut travailler avant la désactivation.
        $this->withHeader('Authorization', "Bearer {$operatorToken}")
            ->getJson('/api/auth/merchant/me')
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->patchJson("/api/auth/merchant/team/{$staff->id}/toggle-active", ['is_active' => false])
            ->assertOk();

        $this->assertFalse($staff->fresh()->is_active);

        // Le même token, déjà émis, ne doit plus fonctionner.
        $this->withHeader('Authorization', "Bearer {$operatorToken}")
            ->getJson('/api/auth/merchant/me')
            ->assertStatus(401);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TeamManagementTest`
Expected: FAIL — routes inexistantes (404).

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    /**
     * GET /api/auth/merchant/team
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $team = $restaurant->staffUsers()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'role', 'is_active', 'created_at'])
            ->map(fn ($s) => [
                'id'         => $s->id,
                'name'       => $s->name,
                'email'      => $s->email,
                'phone'      => $s->phone,
                'role'       => $s->role,
                'is_active'  => $s->is_active,
                'created_at' => $s->created_at?->toIso8601String(),
            ]);

        return response()->json(['team' => $team]);
    }

    /**
     * POST /api/auth/merchant/team
     */
    public function store(Request $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $request->validate([
            'name'     => ['required', 'string', 'max:150'],
            'email'    => ['required', 'email', Rule::unique('staff_users', 'email')],
            'phone'    => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'role'     => ['required', Rule::in(['admin', 'operator'])],
        ], [
            'email.unique' => 'Cette adresse est déjà utilisée par un membre de l\'équipe.',
        ]);

        $staff = $restaurant->staffUsers()->create([
            'name'     => $request->name,
            'email'    => $request->email,
            'phone'    => $request->phone,
            'password' => $request->password,
            'role'     => $request->role,
        ]);

        return response()->json([
            'message' => 'Membre de l\'équipe ajouté.',
            'staff'   => [
                'id' => $staff->id, 'name' => $staff->name, 'email' => $staff->email,
                'phone' => $staff->phone, 'role' => $staff->role, 'is_active' => $staff->is_active,
            ],
        ], 201);
    }

    /**
     * PUT /api/auth/merchant/team/{staffUser}
     */
    public function update(Request $request, StaffUser $staffUser): JsonResponse
    {
        $this->authorizeStaff($request, $staffUser);

        $request->validate([
            'name'     => ['sometimes', 'string', 'max:150'],
            'phone'    => ['sometimes', 'nullable', 'string', 'max:30'],
            'role'     => ['sometimes', Rule::in(['admin', 'operator'])],
            'password' => ['sometimes', 'string', 'min:8'],
        ]);

        $data = $request->only(['name', 'phone', 'role']);
        if ($request->filled('password')) {
            $data['password'] = $request->password;
        }

        $staffUser->update($data);

        return response()->json(['message' => 'Membre mis à jour.']);
    }

    /**
     * PATCH /api/auth/merchant/team/{staffUser}/toggle-active
     */
    public function toggleActive(Request $request, StaffUser $staffUser): JsonResponse
    {
        $restaurant = $this->authorizeStaff($request, $staffUser);

        $request->validate(['is_active' => ['required', 'boolean']]);

        $staffUser->update(['is_active' => $request->boolean('is_active')]);

        if (! $staffUser->is_active) {
            // Les tokens appartiennent au Restaurant (voir CurrentActor) : on
            // révoque uniquement ceux portant l'ability de ce membre précis.
            $restaurant->tokens()
                ->get()
                ->filter(fn ($token) => in_array("staff:{$staffUser->id}", $token->abilities ?? [], true))
                ->each(fn ($token) => $token->delete());
        }

        return response()->json([
            'message' => $staffUser->is_active ? 'Membre réactivé.' : 'Membre désactivé.',
        ]);
    }

    private function authorizeStaff(Request $request, StaffUser $staffUser): Restaurant
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        abort_if($staffUser->restaurant_id !== $restaurant->id, 404);

        return $restaurant;
    }
}
```

- [ ] **Step 4: Add the `staffUsers()` relation on `Restaurant`**

Dans `app/Models/Restaurant.php`, ajouter une méthode (à côté des autres relations existantes, ex. `loyaltyProgram()`) :

```php
    public function staffUsers()
    {
        return $this->hasMany(StaffUser::class);
    }
```

- [ ] **Step 5: Add the routes**

Dans `routes/api.php`, à l'intérieur du groupe `auth/merchant` protégé, sous `admin.only` (après la ligne `notification-preferences`, ligne ~73) :

```php
        Route::get('/team',                          [TeamController::class, 'index'])->middleware('admin.only');
        Route::post('/team',                          [TeamController::class, 'store'])->middleware('admin.only');
        Route::put('/team/{staffUser}',                [TeamController::class, 'update'])->middleware('admin.only');
        Route::patch('/team/{staffUser}/toggle-active', [TeamController::class, 'toggleActive'])->middleware('admin.only');
```

Ajouter l'import :

```php
use App\Http\Controllers\Api\TeamController;
```

- [ ] **Step 6: Run tests**

Run: `php artisan test`
Expected: PASS — 7 nouveaux tests, suite complète verte.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/TeamController.php app/Models/Restaurant.php routes/api.php tests/Feature/Merchant/TeamManagementTest.php
git commit -m "feat: CRUD équipe marchand (admin uniquement), révocation immédiate à la désactivation"
```

---

### Task 8: Attribution des opérations à l'acteur

**Files:**
- Modify: `app/Http/Controllers/Api/MerchantDashboardController.php` (méthodes `grantCashback`, `grantStampOrPoints`, `redeemCashback`, `redeemReward`, `cancelReward`)
- Test: `tests/Feature/Merchant/StaffAttributionTest.php`

**Interfaces:**
- Consumes: `CurrentActor` (Task 2).

**Important** : ne modifier aucune logique de calcul (montants, paliers, verrous) — uniquement ajouter la capture de l'acteur et son écriture en base.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Merchant;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Restaurant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function setup(): array
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('secret123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'stamps',
            'config' => ['goal' => 8],
        ]);
        $client = Client::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Ada',
            'phone' => '+22890000001', 'password' => bcrypt('secret123'),
        ]);
        $card = LoyaltyCard::create([
            'client_id' => $client->id, 'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id, 'progress' => ['stamps_current' => 0],
        ]);
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => 'x', 'role' => 'operator',
        ]);
        $operatorToken = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;
        $adminToken = $restaurant->createToken('merchant-app')->plainTextToken;

        return [$restaurant, $card, $staff, $operatorToken, $adminToken];
    }

    public function test_stamp_granted_by_an_operator_records_their_id(): void
    {
        [, $card, $staff, $operatorToken] = $this->setup();

        $this->withHeader('Authorization', "Bearer {$operatorToken}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", [])
            ->assertOk();

        $this->assertDatabaseHas('loyalty_transactions', [
            'loyalty_card_id' => $card->id, 'type' => 'stamp', 'staff_user_id' => $staff->id,
        ]);
    }

    public function test_stamp_granted_by_the_admin_records_no_staff_id(): void
    {
        [, $card, , , $adminToken] = $this->setup();

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", [])
            ->assertOk();

        $this->assertDatabaseHas('loyalty_transactions', [
            'loyalty_card_id' => $card->id, 'type' => 'stamp', 'staff_user_id' => null,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StaffAttributionTest`
Expected: FAIL — `staff_user_id` toujours `null`, même pour l'opérateur.

- [ ] **Step 3: Modify `grantCashback`**

Ajouter juste avant `$restaurantId = $restaurant->id;` :

```php
        $staffUserId = \App\Support\CurrentActor::resolve($request)->staffUser?->id;
```

Ajouter `$staffUserId` à la liste `use (...)` de la fermeture `DB::transaction`, et dans l'`insert` `loyalty_transactions`, ajouter la clé :

```php
                'staff_user_id'         => $staffUserId,
```

- [ ] **Step 4: Modify `grantStampOrPoints`**

Même changement : ajouter `$staffUserId = \App\Support\CurrentActor::resolve($request)->staffUser?->id;` avant `$restaurantId = $restaurant->id;`, l'ajouter à la liste `use (...)` de la fermeture, et dans l'`insert` du type `'stamp'` (pas celui de `cycle_completed`, qui reste sans acteur — un signal technique interne), ajouter :

```php
                'staff_user_id'          => $staffUserId,
```

- [ ] **Step 5: Modify `redeemCashback`**

Ajouter `$staffUserId = \App\Support\CurrentActor::resolve($request)->staffUser?->id;` avant le `Cache::lock`, l'ajouter à la liste `use (...)` de la fermeture, et dans l'`insert` :

```php
                    'staff_user_id'         => $staffUserId,
```

- [ ] **Step 6: Modify `redeemReward` and `cancelReward`**

Dans `redeemReward`, la ligne `$loyaltyReward->update([...])` devient :

```php
            $loyaltyReward->update([
                'status'               => 'used',
                'used_at'              => now(),
                'used_by_staff_user_id' => \App\Support\CurrentActor::resolve($request)->staffUser?->id,
            ]);
```

Dans `cancelReward` :

```php
        $loyaltyReward->update([
            'status'                    => 'canceled',
            'canceled_at'               => now(),
            'cancel_reason'             => $request->input('reason'),
            'canceled_by_staff_user_id' => \App\Support\CurrentActor::resolve($request)->staffUser?->id,
        ]);
```

- [ ] **Step 7: Run tests**

Run: `php artisan test`
Expected: PASS — les 2 nouveaux tests, et toute la suite existante (`AddStampTest`, `CashbackTest`, etc.) reste verte puisque `staff_user_id` reste `null` pour un acteur admin, comportement identique à avant pour tous les tests qui ne s'en soucient pas.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/MerchantDashboardController.php tests/Feature/Merchant/StaffAttributionTest.php
git commit -m "feat: trace l'opérateur qui effectue chaque opération (tampon/cashback/récompense)"
```

---

### Task 9: Historique consultable côté marchand

**Files:**
- Modify: `app/Http/Controllers/Api/MerchantDashboardController.php` (nouvelle méthode `clientHistory`)
- Test: `tests/Feature/Merchant/ClientHistoryTest.php`

**Interfaces:**
- Produces: `GET /api/merchant/clients/{loyaltyCard}/history` (route déjà ajoutée en Task 4) → `{history: [{type, value, montant_commande_fcfa, created_at, staff_name, staff_role}]}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Merchant;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Restaurant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_shows_who_performed_each_operation(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('secret123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'stamps',
            'config' => ['goal' => 8],
        ]);
        $client = Client::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Ada',
            'phone' => '+22890000001', 'password' => bcrypt('secret123'),
        ]);
        $card = LoyaltyCard::create([
            'client_id' => $client->id, 'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id, 'progress' => ['stamps_current' => 0],
        ]);
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => 'x', 'role' => 'operator',
        ]);
        $operatorToken = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;
        $adminToken = $restaurant->createToken('merchant-app')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$operatorToken}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", [])
            ->assertOk();
        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", [])
            ->assertOk();

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson("/api/merchant/clients/{$card->id}/history");

        $response->assertOk();
        $entries = $response->json('history');
        $this->assertCount(2, $entries);
        // Plus récent en premier.
        $this->assertNull($entries[0]['staff_name']);
        $this->assertSame('Jean', $entries[1]['staff_name']);
        $this->assertSame('operator', $entries[1]['staff_role']);
    }

    public function test_operator_can_also_view_history(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce2@example.com', 'password' => bcrypt('secret123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'stamps',
            'config' => ['goal' => 8],
        ]);
        $client = Client::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Ada',
            'phone' => '+22890000002', 'password' => bcrypt('secret123'),
        ]);
        $card = LoyaltyCard::create([
            'client_id' => $client->id, 'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id, 'progress' => ['stamps_current' => 0],
        ]);
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean2@example.com', 'password' => 'x', 'role' => 'operator',
        ]);
        $operatorToken = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$operatorToken}")
            ->getJson("/api/merchant/clients/{$card->id}/history")
            ->assertOk();
    }

    public function test_cannot_view_history_of_a_card_from_another_restaurant(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce3@example.com', 'password' => bcrypt('secret123'),
        ]);
        $otherRestaurant = Restaurant::create([
            'name' => 'Autre', 'category' => 'Restaurant',
            'email' => 'autre@example.com', 'password' => bcrypt('secret123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $otherRestaurant->id, 'name' => 'Programme', 'type' => 'stamps',
            'config' => ['goal' => 8],
        ]);
        $client = Client::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Ada',
            'phone' => '+22890000003', 'password' => bcrypt('secret123'),
        ]);
        $card = LoyaltyCard::create([
            'client_id' => $client->id, 'restaurant_id' => $otherRestaurant->id,
            'loyalty_program_id' => $program->id, 'progress' => ['stamps_current' => 0],
        ]);
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/merchant/clients/{$card->id}/history")
            ->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ClientHistoryTest`
Expected: FAIL — méthode `clientHistory` inexistante (500) ou route déjà déclarée en Task 4 mais sans handler.

- [ ] **Step 3: Write the method**

Ajouter dans `MerchantDashboardController.php`, à proximité de `addStamp`/`redeemCashback` :

```php
    /**
     * GET /api/merchant/clients/{loyaltyCard}/history
     *
     * Historique consultable par l'admin ET l'opérateur (contrairement à
     * `/merchant/clients` en liste, réservé admin) — voir spec équipe.
     */
    public function clientHistory(Request $request, LoyaltyCard $loyaltyCard): JsonResponse
    {
        $this->authorizeCard($request, $loyaltyCard);

        $entries = DB::table('loyalty_transactions')
            ->leftJoin('staff_users', 'staff_users.id', '=', 'loyalty_transactions.staff_user_id')
            ->where('loyalty_transactions.loyalty_card_id', $loyaltyCard->id)
            ->whereIn('loyalty_transactions.type', ['stamp', 'cashback_earn', 'cashback_redeem'])
            ->where('loyalty_transactions.status', 'valid')
            ->orderByDesc('loyalty_transactions.created_at')
            ->orderByDesc('loyalty_transactions.id')
            ->limit(100)
            ->get([
                'loyalty_transactions.type',
                'loyalty_transactions.value',
                'loyalty_transactions.montant_commande_fcfa',
                'loyalty_transactions.created_at',
                'staff_users.name as staff_name',
                'staff_users.role as staff_role',
            ]);

        $numeric = function ($value) {
            if ($value === null) {
                return null;
            }
            $float = (float) $value;

            return floor($float) == $float ? (int) $float : $float;
        };

        $history = $entries->map(fn ($row) => [
            'type'                  => $row->type,
            'value'                 => $numeric($row->value),
            'montant_commande_fcfa' => $numeric($row->montant_commande_fcfa),
            'created_at'            => $row->created_at,
            'staff_name'            => $row->staff_name,
            'staff_role'            => $row->staff_role,
        ]);

        return response()->json(['history' => $history]);
    }
```

- [ ] **Step 4: Run tests**

Run: `php artisan test`
Expected: PASS — 3 nouveaux tests, suite complète verte.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/MerchantDashboardController.php tests/Feature/Merchant/ClientHistoryTest.php
git commit -m "feat: historique client marchand avec attribution (admin + opérateur)"
```

---

### Task 10: `RestaurantAccount` — champs acteur (Flutter)

**Files:**
- Modify: `lib/features/merchant/models/restaurant_account.dart`
- Test: `test/features/merchant/models/restaurant_account_test.dart` (nouveau)

**Interfaces:**
- Produces: `RestaurantAccount.actorType` (`String`, `'restaurant'|'staff'`), `.staffName` (`String?`), `.staffRole` (`String?`).

- [ ] **Step 1: Write the failing test**

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:miva_fid/features/merchant/models/restaurant_account.dart';

void main() {
  group('RestaurantAccount.fromJson — champ actor', () {
    test('un compte Restaurant classique est actorType restaurant / role admin', () {
      final account = RestaurantAccount.fromJson({
        'id': '1', 'uuid': 'u1', 'email': 'a@a.com',
        'actor': {'type': 'restaurant', 'id': null, 'name': null, 'role': 'admin'},
      });

      expect(account.actorType, 'restaurant');
      expect(account.staffRole, 'admin');
      expect(account.staffName, isNull);
    });

    test('un compte opérateur porte son nom et son rôle', () {
      final account = RestaurantAccount.fromJson({
        'id': '1', 'uuid': 'u1', 'email': 'a@a.com',
        'actor': {'type': 'staff', 'id': 5, 'name': 'Jean', 'role': 'operator'},
      });

      expect(account.actorType, 'staff');
      expect(account.staffName, 'Jean');
      expect(account.staffRole, 'operator');
    });

    test('absence de la clé actor retombe sur restaurant/admin (rétrocompatibilité)', () {
      final account = RestaurantAccount.fromJson({
        'id': '1', 'uuid': 'u1', 'email': 'a@a.com',
      });

      expect(account.actorType, 'restaurant');
      expect(account.staffRole, 'admin');
    });
  });
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `flutter test test/features/merchant/models/restaurant_account_test.dart`
Expected: FAIL — `actorType`/`staffName`/`staffRole` n'existent pas.

- [ ] **Step 3: Modify the model**

Ajouter les champs après `smsCredits` :

```dart
  /// `restaurant` (le propriétaire, toujours admin) ou `staff` (membre de
  /// l'équipe). Absent de la réponse (comptes créés avant cette
  /// fonctionnalité) : retombe sur `restaurant`/`admin`.
  final String actorType;
  final String? staffName;
  final String staffRole;
```

Dans le constructeur :

```dart
    this.actorType = 'restaurant',
    this.staffName,
    this.staffRole = 'admin',
```

Dans `fromJson` :

```dart
      actorType: (json['actor'] as Map?)?['type'] as String? ?? 'restaurant',
      staffName: (json['actor'] as Map?)?['name'] as String?,
      staffRole: (json['actor'] as Map?)?['role'] as String? ?? 'admin',
```

- [ ] **Step 4: Run tests**

Run: `flutter test test/features/merchant/models/restaurant_account_test.dart`
Expected: PASS (3 tests).

- [ ] **Step 5: Add `isAdminProvider`**

Dans `lib/features/merchant/providers/merchant_auth_provider.dart`, ajouter en bas du fichier (hors classe) :

```dart
/// `true` pour un compte Restaurant classique ou un membre d'équipe rôle
/// admin ; `false` pour un opérateur — pilote la navigation réduite
/// (`MerchantShell`) et la redirection du router.
final isAdminProvider = Provider<bool>((ref) {
  final restaurant = ref.watch(merchantAuthProvider.select((s) => s.restaurant));
  return restaurant == null || restaurant.staffRole == 'admin';
});
```

Vérifier que `riverpod`'s `Provider` est déjà importé dans ce fichier (il l'est, via `flutter_riverpod`) — sinon ajouter `import 'package:flutter_riverpod/flutter_riverpod.dart';`.

- [ ] **Step 6: Run analyze and full test suite**

Run: `flutter analyze lib/features/merchant/models/restaurant_account.dart lib/features/merchant/providers/merchant_auth_provider.dart && flutter test`
Expected: clean analyze, all tests pass.

- [ ] **Step 7: Commit**

```bash
git add lib/features/merchant/models/restaurant_account.dart lib/features/merchant/providers/merchant_auth_provider.dart test/features/merchant/models/restaurant_account_test.dart
git commit -m "feat: RestaurantAccount porte l'acteur (admin/opérateur), isAdminProvider"
```

---

### Task 11: Connexion opérateur côté Flutter

**Files:**
- Modify: `lib/core/api/services/merchant_auth_service.dart`
- Modify: `lib/core/api/repositories/merchant_auth_repository.dart`
- Modify: `lib/features/merchant/providers/merchant_auth_provider.dart`
- Modify: `lib/features/onboarding/screens/merchant_auth_screen.dart`

**Interfaces:**
- Consumes: `POST /auth/merchant/staff/login` (Task 5).
- Produces: `MerchantAuthNotifier.staffLogin(String email, String password): Future<bool>`.

- [ ] **Step 1: Add the service method**

Dans `merchant_auth_service.dart`, à côté de `login()` :

```dart
  Future<Map<String, dynamic>> staffLogin(String email, String password) =>
      _guard(() async {
        final response = await _apiClient.dio.post('/auth/merchant/staff/login', data: {
          'email': email,
          'password': password,
        });
        return response.data as Map<String, dynamic>;
      });
```

- [ ] **Step 2: Add the repository method**

Dans `merchant_auth_repository.dart`, à côté de `login()` :

```dart
  Future<RestaurantAccount> staffLogin(String email, String password) async {
    final response = await _authService.staffLogin(email, password);
    final token = response['access_token'];
    if (token != null) {
      await _tokenStorage.saveToken(token);
    }
    return RestaurantAccount.fromJson(response['restaurant'] ?? {});
  }
```

- [ ] **Step 3: Add the provider method**

Dans `merchant_auth_provider.dart`, à côté de `login()` :

```dart
  Future<bool> staffLogin(String email, String password) async {
    state = state.copyWith(clearError: true);
    try {
      final restaurant = await _authRepository.staffLogin(email, password);
      state = MerchantAuthState(isAuthenticated: true, restaurant: restaurant);
      return true;
    } catch (e) {
      state = state.copyWith(lastError: e);
      return false;
    }
  }
```

- [ ] **Step 4: Run analyze**

Run: `flutter analyze lib/core/api/services/merchant_auth_service.dart lib/core/api/repositories/merchant_auth_repository.dart lib/features/merchant/providers/merchant_auth_provider.dart`
Expected: clean.

- [ ] **Step 5: Add the login-mode toggle to the screen**

Dans `merchant_auth_screen.dart`, ajouter le champ d'état (à côté de `_isLogin`, `_loading`, etc.) :

```dart
  bool _loginAsStaff = false; // uniquement pertinent quand _isLogin == true
```

Modifier `_handleSubmit()` — remplacer la branche `if (_isLogin) { ... }` :

```dart
      if (_isLogin) {
        // --- CONNEXION ---
        final ok = _loginAsStaff
            ? await ref.read(merchantAuthProvider.notifier).staffLogin(email, password)
            : await ref.read(merchantAuthProvider.notifier).login(email, password);

        if (!mounted) return;

        if (!ok) {
          ToastService.showError(_translatedError(
            ErrorContext.login,
            fallback: ErrorMessages.loginFailed,
          ));
          return;
        }

        final restaurant = ref.read(merchantAuthProvider).restaurant;
        if (restaurant?.hasBusinessInfo ?? false) {
          context.go('/merchant');
        } else {
          context.go('/auth/merchant/step1');
        }
      } else {
```

Ajouter la bascule visuelle juste après le "Segmented Capsule Control" (Inscription/Connexion, après la `Container` fermée par `const SizedBox(height: Sp.md),` qui la suit — chercher ce bloc précisément dans le fichier avant d'insérer), visible seulement si `_isLogin` :

```dart
                if (_isLogin) ...[
                  const SizedBox(height: Sp.sm),
                  Row(
                    children: [
                      Expanded(
                        child: TextButton(
                          onPressed: () => setState(() => _loginAsStaff = false),
                          style: TextButton.styleFrom(
                            foregroundColor: !_loginAsStaff ? AppColors.merchant : AppColors.textSecondary,
                          ),
                          child: Text(
                            'Administrateur',
                            style: TextStyle(fontWeight: !_loginAsStaff ? FontWeight.bold : FontWeight.normal),
                          ),
                        ),
                      ),
                      Expanded(
                        child: TextButton(
                          onPressed: () => setState(() => _loginAsStaff = true),
                          style: TextButton.styleFrom(
                            foregroundColor: _loginAsStaff ? AppColors.merchant : AppColors.textSecondary,
                          ),
                          child: Text(
                            'Opérateur',
                            style: TextStyle(fontWeight: _loginAsStaff ? FontWeight.bold : FontWeight.normal),
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
```

Lire le fichier autour de la zone d'insertion avant d'écrire le diff final — le repère exact ("Segmented Capsule Control") a pu légèrement bouger depuis la rédaction de ce plan.

- [ ] **Step 6: Run analyze**

Run: `flutter analyze lib/features/onboarding/screens/merchant_auth_screen.dart`
Expected: clean.

- [ ] **Step 7: Manual smoke test**

Lancer l'app (`flutter run` ou build web), aller sur l'écran de connexion marchand, basculer "Opérateur", tenter une connexion avec un compte opérateur créé via `POST /auth/merchant/team` (Task 7) — vérifier l'arrivée sur le dashboard sans erreur.

- [ ] **Step 8: Commit**

```bash
git add lib/core/api/services/merchant_auth_service.dart lib/core/api/repositories/merchant_auth_repository.dart lib/features/merchant/providers/merchant_auth_provider.dart lib/features/onboarding/screens/merchant_auth_screen.dart
git commit -m "feat: bascule connexion Administrateur/Opérateur côté marchand"
```

---

### Task 12: Navigation réduite pour l'opérateur

**Files:**
- Modify: `lib/features/merchant/screens/merchant_shell.dart`
- Modify: `lib/core/router/app_router.dart`

**Interfaces:**
- Consumes: `isAdminProvider` (Task 10).

- [ ] **Step 1: Add the router redirect**

Dans `app_router.dart`, dans le bloc `redirect:` (fonction déjà existante), juste après la ligne `return null;` qui termine le bloc `/merchant/` (celle qui suit la vérification `hasLoyaltyProgram`) :

```dart
      if (location.startsWith('/merchant/') || location == '/merchant') {
        final merchantAuth = ref.read(merchantAuthProvider);
        if (!merchantAuth.isAuthenticated) return '/auth/merchant/auth';
        final restaurant = merchantAuth.restaurant;
        if (!(restaurant?.hasBusinessInfo ?? false)) return '/auth/merchant/step1';
        if (!(restaurant?.hasLocation ?? false)) return '/auth/merchant/location';
        if (!(restaurant?.hasLoyaltyProgram ?? false)) return '/auth/merchant/step2';

        // Opérateur : uniquement l'écran de validation, jamais le dashboard,
        // la clientèle, les campagnes ou la configuration (voir spec équipe).
        final isAdmin = ref.read(isAdminProvider);
        if (!isAdmin && !location.startsWith('/merchant/validate')) {
          return '/merchant/validate';
        }

        return null;
      }
```

Vérifier l'import de `isAdminProvider` en haut du fichier (ajouter si absent) :

```dart
import '../../features/merchant/providers/merchant_auth_provider.dart';
```

(Probablement déjà importé pour `merchantAuthProvider` — réutiliser le même import.)

- [ ] **Step 2: Hide the bottom nav bar for an operator**

Dans `merchant_shell.dart`, dans `build()`, ajouter la lecture du provider à côté de `hideNav` :

```dart
    final isAdmin = ref.watch(isAdminProvider);
```

Modifier la ligne `bottomNavigationBar:` :

```dart
      bottomNavigationBar: (hideNav || !isAdmin) ? const SizedBox.shrink() : Container(
```

Modifier également `appBar:` pour ne jamais afficher l'en-tête complet (nom du commerce, cloche notifications, plan SMS — informations administratives) à un opérateur, en plus de la condition `showHeader` existante :

```dart
      appBar: (showHeader && !hideNav && isAdmin)
```

Ajouter l'import du provider en haut du fichier si absent :

```dart
import '../providers/merchant_auth_provider.dart';
```

- [ ] **Step 3: Run analyze**

Run: `flutter analyze lib/features/merchant/screens/merchant_shell.dart lib/core/router/app_router.dart`
Expected: clean.

- [ ] **Step 4: Manual smoke test**

Se connecter en tant qu'opérateur (Task 11) : vérifier l'atterrissage direct sur `/merchant/validate`, l'absence de barre de navigation et d'en-tête, et qu'une tentative de saisie manuelle de `/merchant/clients` (ou `/merchant/more/...`) dans un lien profond renvoie bien vers `/merchant/validate`.

- [ ] **Step 5: Commit**

```bash
git add lib/features/merchant/screens/merchant_shell.dart lib/core/router/app_router.dart
git commit -m "feat: navigation opérateur réduite à l'écran de validation"
```

---

### Task 13: Écran Équipe — vrai CRUD

**Files:**
- Rewrite: `lib/features/merchant/screens/team_screen.dart`
- Create: `lib/features/merchant/models/team_member.dart`
- Create: `lib/features/merchant/providers/team_provider.dart`
- Create: `lib/core/api/services/team_service.dart`

**Interfaces:**
- Consumes: `GET/POST/PUT/PATCH /auth/merchant/team*` (Task 7).

- [ ] **Step 1: Create the model**

```dart
class TeamMember {
  final int id;
  final String name;
  final String email;
  final String? phone;
  final String role; // 'admin' | 'operator'
  final bool isActive;

  const TeamMember({
    required this.id,
    required this.name,
    required this.email,
    this.phone,
    required this.role,
    required this.isActive,
  });

  factory TeamMember.fromJson(Map<String, dynamic> json) {
    return TeamMember(
      id: json['id'] as int,
      name: json['name'] as String? ?? '',
      email: json['email'] as String? ?? '',
      phone: json['phone'] as String?,
      role: json['role'] as String? ?? 'operator',
      isActive: json['is_active'] as bool? ?? true,
    );
  }
}
```

- [ ] **Step 2: Create the service**

```dart
import '../../../core/api/core/api_client.dart';

class TeamService {
  final ApiClient _apiClient;
  TeamService(this._apiClient);

  Future<List<Map<String, dynamic>>> list() async {
    final response = await _apiClient.dio.get('/auth/merchant/team');
    return List<Map<String, dynamic>>.from(response.data['team'] as List);
  }

  Future<void> invite({
    required String name,
    required String email,
    String? phone,
    required String password,
    required String role,
  }) async {
    await _apiClient.dio.post('/auth/merchant/team', data: {
      'name': name,
      'email': email,
      if (phone != null && phone.isNotEmpty) 'phone': phone,
      'password': password,
      'role': role,
    });
  }

  Future<void> update(int id, Map<String, dynamic> data) async {
    await _apiClient.dio.put('/auth/merchant/team/$id', data: data);
  }

  Future<void> toggleActive(int id, bool isActive) async {
    await _apiClient.dio.patch('/auth/merchant/team/$id/toggle-active', data: {
      'is_active': isActive,
    });
  }
}
```

Utiliser la même gestion d'erreurs Dio que les autres services (`merchant_auth_service.dart` : `DioException` → `ValidationException`/`ServerException`/`NetworkException`). Copier le bloc `_guard`/`_throwFromDio`/`_backendMessage` de `merchant_auth_service.dart` tel quel dans cette classe (même pattern, pas de dépendance croisée entre services).

- [ ] **Step 3: Create the provider**

```dart
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/providers/api_providers.dart';
import '../../../core/api/services/team_service.dart';
import '../models/team_member.dart';

final teamServiceProvider = Provider<TeamService>((ref) {
  return TeamService(ref.watch(apiClientProvider));
});

class TeamNotifier extends StateNotifier<AsyncValue<List<TeamMember>>> {
  final TeamService _service;
  TeamNotifier(this._service) : super(const AsyncValue.loading()) {
    refresh();
  }

  Future<void> refresh() async {
    state = const AsyncValue.loading();
    try {
      final raw = await _service.list();
      state = AsyncValue.data(raw.map(TeamMember.fromJson).toList());
    } catch (e, st) {
      state = AsyncValue.error(e, st);
    }
  }

  Future<bool> invite({
    required String name,
    required String email,
    String? phone,
    required String password,
    required String role,
  }) async {
    try {
      await _service.invite(name: name, email: email, phone: phone, password: password, role: role);
      await refresh();
      return true;
    } catch (_) {
      return false;
    }
  }

  Future<bool> toggleActive(int id, bool isActive) async {
    try {
      await _service.toggleActive(id, isActive);
      await refresh();
      return true;
    } catch (_) {
      return false;
    }
  }
}

final teamNotifierProvider =
    StateNotifierProvider<TeamNotifier, AsyncValue<List<TeamMember>>>((ref) {
  return TeamNotifier(ref.watch(teamServiceProvider));
});
```

Vérifier le nom exact du provider fournissant `ApiClient` dans `core/api/providers/api_providers.dart` (`apiClientProvider` supposé ici — lire ce fichier avant d'écrire et ajuster si le nom réel diffère).

- [ ] **Step 4: Rewrite the screen**

Remplacer entièrement `team_screen.dart`. Réutiliser la mise en page existante (liste dans une carte blanche bordée, `AppColors`/`Sp`/`Rd`) mais alimentée par `teamNotifierProvider` :

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

import '../../../core/theme/app_colors.dart';
import '../../../core/theme/app_spacing.dart';
import '../../../core/theme/app_text_styles.dart';
import '../../../core/widgets/app_button.dart';
import '../../../core/widgets/app_input.dart';
import '../../client/providers/settings_provider.dart';
import '../models/team_member.dart';
import '../providers/team_provider.dart';

class TeamScreen extends ConsumerWidget {
  const TeamScreen({super.key});

  void _showInviteSheet(BuildContext context, WidgetRef ref) {
    final nameCtrl = TextEditingController();
    final emailCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    final passwordCtrl = TextEditingController();
    String role = 'operator';
    bool saving = false;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      builder: (sheetContext) => StatefulBuilder(
        builder: (sheetContext, setSheetState) => Padding(
          padding: EdgeInsets.only(
            left: Sp.md, right: Sp.md, top: Sp.md,
            bottom: MediaQuery.of(sheetContext).viewInsets.bottom + Sp.md,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('Inviter un membre', style: AppTextStyles.h3()),
              const SizedBox(height: Sp.md),
              AppInput(label: 'Nom', controller: nameCtrl, accentColor: AppColors.merchant),
              const SizedBox(height: Sp.sm),
              AppInput(label: 'Email', controller: emailCtrl, keyboardType: TextInputType.emailAddress, accentColor: AppColors.merchant),
              const SizedBox(height: Sp.sm),
              AppInput(label: 'Téléphone (optionnel)', controller: phoneCtrl, keyboardType: TextInputType.phone, accentColor: AppColors.merchant),
              const SizedBox(height: Sp.sm),
              AppInput(label: 'Mot de passe', controller: passwordCtrl, obscureText: true, accentColor: AppColors.merchant),
              const SizedBox(height: Sp.sm),
              Row(
                children: [
                  Expanded(
                    child: ChoiceChip(
                      label: const Text('Opérateur'),
                      selected: role == 'operator',
                      onSelected: (_) => setSheetState(() => role = 'operator'),
                    ),
                  ),
                  const SizedBox(width: Sp.sm),
                  Expanded(
                    child: ChoiceChip(
                      label: const Text('Administrateur'),
                      selected: role == 'admin',
                      onSelected: (_) => setSheetState(() => role = 'admin'),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: Sp.md),
              AppButton.merchant(
                'Inviter',
                loading: saving,
                onPressed: () async {
                  setSheetState(() => saving = true);
                  final ok = await ref.read(teamNotifierProvider.notifier).invite(
                        name: nameCtrl.text.trim(),
                        email: emailCtrl.text.trim(),
                        phone: phoneCtrl.text.trim(),
                        password: passwordCtrl.text,
                        role: role,
                      );
                  if (!sheetContext.mounted) return;
                  if (ok) {
                    Navigator.pop(sheetContext);
                  } else {
                    setSheetState(() => saving = false);
                    ScaffoldMessenger.of(sheetContext).showSnackBar(
                      const SnackBar(content: Text('Impossible d\'inviter ce membre.')),
                    );
                  }
                },
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.watch(appBrightnessProvider);
    final teamAsync = ref.watch(teamNotifierProvider);

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        title: const Text('Équipe'),
        backgroundColor: Colors.transparent,
        elevation: 0,
        actions: [
          IconButton(
            icon: const Icon(LucideIcons.userPlus),
            onPressed: () => _showInviteSheet(context, ref),
          ),
        ],
      ),
      body: teamAsync.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => Center(
          child: Text('Erreur : $e', style: AppTextStyles.bodyMd()),
        ),
        data: (team) => team.isEmpty
            ? Center(
                child: Text(
                  'Aucun membre d\'équipe. Invitez votre premier opérateur.',
                  style: AppTextStyles.bodyMd().copyWith(color: AppColors.textSecondary),
                ),
              )
            : ListView.separated(
                padding: const EdgeInsets.all(Sp.md),
                itemCount: team.length,
                separatorBuilder: (_, __) => const SizedBox(height: Sp.sm),
                itemBuilder: (context, i) => _TeamMemberTile(member: team[i]),
              ),
      ),
    );
  }
}

class _TeamMemberTile extends ConsumerWidget {
  final TeamMember member;
  const _TeamMemberTile({required this.member});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Container(
      padding: const EdgeInsets.all(Sp.md),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: Rd.card,
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(member.name, style: AppTextStyles.labelBold()),
                Text(member.email, style: AppTextStyles.caption().copyWith(color: AppColors.textSecondary)),
                Text(
                  member.role == 'admin' ? 'Administrateur' : 'Opérateur',
                  style: AppTextStyles.caption().copyWith(color: AppColors.merchant, fontWeight: FontWeight.bold),
                ),
              ],
            ),
          ),
          Switch(
            value: member.isActive,
            activeThumbColor: Colors.white,
            activeTrackColor: AppColors.merchant,
            onChanged: (val) => ref.read(teamNotifierProvider.notifier).toggleActive(member.id, val),
          ),
        ],
      ),
    );
  }
}
```

- [ ] **Step 5: Run analyze**

Run: `flutter analyze lib/features/merchant/screens/team_screen.dart lib/features/merchant/models/team_member.dart lib/features/merchant/providers/team_provider.dart lib/core/api/services/team_service.dart`
Expected: clean (ajuster les imports selon les noms réels trouvés à l'étape 3).

- [ ] **Step 6: Manual smoke test**

Se connecter en admin, aller sur Plus → Équipe, inviter un opérateur, vérifier son apparition dans la liste, basculer son statut actif/inactif, vérifier que l'opérateur ne peut alors plus se connecter.

- [ ] **Step 7: Commit**

```bash
git add lib/features/merchant/screens/team_screen.dart lib/features/merchant/models/team_member.dart lib/features/merchant/providers/team_provider.dart lib/core/api/services/team_service.dart
git commit -m "feat: écran Équipe branché sur le vrai backend (invite/liste/activation)"
```

---

### Task 14: Historique côté `client_detail_screen.dart`

**Files:**
- Modify: `lib/features/merchant/screens/client_detail_screen.dart`

**Interfaces:**
- Consumes: `GET /merchant/clients/{id}/history` (Task 9).

- [ ] **Step 1: Locate the existing history icon**

Lire `client_detail_screen.dart` autour de la ligne 246 (icône `LucideIcons.history` repérée lors du brainstorming) pour voir son contexte exact (bouton ? onglet ? callback vide ?) avant d'écrire le câblage — le fichier a pu changer depuis.

- [ ] **Step 2: Add a history fetch + display**

Ajouter un appel à `GET /merchant/clients/{id}/history` (via le service HTTP déjà utilisé par cet écran pour les autres appels marchand — identifier son nom exact en lisant les imports du fichier) déclenché par l'icône, affichant une feuille (`showModalBottomSheet`) listant chaque entrée avec :

```dart
Text(
  entry['staff_name'] != null
      ? 'Effectué par : ${entry['staff_name']} — ${entry['staff_role'] == 'admin' ? 'Administrateur' : 'Opérateur'}'
      : 'Effectué par : Administrateur',
  style: AppTextStyles.caption().copyWith(color: AppColors.textSecondary),
),
```

Le format exact du widget (liste, carte par ligne) doit suivre le style déjà utilisé ailleurs dans ce même écran pour rester cohérent — pas de nouveau design system introduit pour un seul écran.

- [ ] **Step 3: Run analyze**

Run: `flutter analyze lib/features/merchant/screens/client_detail_screen.dart`
Expected: clean.

- [ ] **Step 4: Manual smoke test**

Depuis la fiche d'un client ayant au moins une opération enregistrée par un opérateur (Task 8 les crée en base de test, ou effectuer une vraie opération via l'écran de validation avec un compte opérateur), ouvrir l'historique et vérifier la ligne "Effectué par : Jean — Opérateur".

- [ ] **Step 5: Commit**

```bash
git add lib/features/merchant/screens/client_detail_screen.dart
git commit -m "feat: affiche qui a effectué chaque opération dans l'historique client marchand"
```

---

## Self-Review

**Couverture de la spec** :
- Deux rôles stricts, contrainte DB → Task 1. ✓
- Token Restaurant-scoped + résolution d'acteur → Task 2. ✓
- Admin : accès complet inchangé → Task 3 (delegation transparente), Task 4 (middleware n'affecte que les opérateurs). ✓
- Opérateur : accès strictement opérationnel, refus sur le reste → Task 4. ✓
- Chaque opérateur son propre compte, authentification individuelle → Task 5. ✓
- Attribution en base + affichage "Effectué par" → Tasks 8, 9, 14. ✓
- Interface opérateur simplifiée (pas de stats/config/équipe visibles) → Task 12. ✓
- Équipe réservée à l'admin, inviter/désactiver/modifier, deux rôles seulement → Tasks 7, 13. ✓
- Pas de suppression dure → Task 7 ne l'implémente pas (conforme). ✓
- Règles métier des programmes non touchées → aucune tâche ne modifie `LoyaltyTierService`, les calculs de paliers/cashback, ou la logique de `crossedTiers`/`grantStampOrPoints` au-delà de l'ajout d'un champ d'attribution. ✓

**Balayage placeholders** : aucun "TBD"/"TODO" trouvé. Deux points signalés explicitement comme nécessitant une relecture du fichier réel avant d'écrire le diff final (Task 11 Step 5, Task 14 Step 1) — pas des placeholders de contenu, mais un avertissement légitime que ce plan a été écrit à partir d'une lecture antérieure du code et que les numéros de ligne/repères textuels peuvent avoir dérivé.

**Cohérence des types** : `CurrentActor::resolve()` retourne toujours la même forme (`type`/`staffUser`/`role`) utilisée identiquement dans les Tasks 2, 3, 4, 6, 8, 9. `RestaurantPayload::build()` (Task 3) est bien la seule source du corps `restaurant` réutilisée à l'identique en Task 5. `isAdminProvider` (Task 10) est le seul point de lecture du rôle côté Flutter, réutilisé sans redéfinition en Tasks 12 et 13 (implicitement, via l'appartenance à l'app déjà authentifiée).
