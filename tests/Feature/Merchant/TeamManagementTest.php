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

        // Un second opérateur, actif lui aussi, dont le token ne doit pas
        // être touché par la désactivation de Jean — c'est ce qui prouve
        // que le filtre de révocation cible uniquement `staff:{$staff->id}`
        // et ne purge pas tous les tokens du restaurant.
        $otherStaff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Paul',
            'email' => 'paul3@example.com', 'password' => bcrypt('operatorpass'), 'role' => 'operator',
        ]);
        $otherOperatorToken = $restaurant->createToken("staff:{$otherStaff->id}", ["staff:{$otherStaff->id}"])->plainTextToken;

        // L'opérateur peut travailler avant la désactivation.
        $this->withHeader('Authorization', "Bearer {$operatorToken}")
            ->getJson('/api/auth/merchant/me')
            ->assertOk();

        // Le guard Sanctum ('sanctum') met en cache l'utilisateur résolu sur
        // l'instance du guard (RequestGuard::$user) — sans purge, la requête
        // suivante réutiliserait l'opérateur au lieu de relire le nouveau
        // token depuis l'en-tête. Nécessaire uniquement parce qu'un seul test
        // enchaîne plusieurs acteurs sur la même app ; sans impact en prod où
        // chaque requête a son propre process/guard.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->patchJson("/api/auth/merchant/team/{$staff->id}/toggle-active", ['is_active' => false])
            ->assertOk();

        $this->assertFalse($staff->fresh()->is_active);

        // Le même token, déjà émis, ne doit plus fonctionner.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$operatorToken}")
            ->getJson('/api/auth/merchant/me')
            ->assertStatus(401);

        // Ni le token de l'admin qui a fait l'action, ni celui d'un autre
        // opérateur actif, ne doivent avoir été révoqués au passage.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson('/api/auth/merchant/me')
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$otherOperatorToken}")
            ->getJson('/api/auth/merchant/me')
            ->assertOk();
    }

    public function test_resetting_a_staff_password_revokes_their_existing_token(): void
    {
        [$restaurant, $adminToken] = $this->adminToken();
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean4@example.com', 'password' => bcrypt('oldpass'), 'role' => 'operator',
        ]);
        $operatorToken = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;

        // L'ancien token fonctionne avant la réinitialisation.
        $this->withHeader('Authorization', "Bearer {$operatorToken}")
            ->getJson('/api/auth/merchant/me')
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->putJson("/api/auth/merchant/team/{$staff->id}", ['password' => 'brandnewpass'])
            ->assertOk();

        // Le mot de passe a bien changé...
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('brandnewpass', $staff->fresh()->password));

        // ...et l'ancien token, potentiellement compromis, ne doit plus
        // fonctionner.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$operatorToken}")
            ->getJson('/api/auth/merchant/me')
            ->assertStatus(401);
    }

    public function test_updating_a_staff_member_without_a_password_does_not_revoke_their_token(): void
    {
        [$restaurant, $adminToken] = $this->adminToken();
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean5@example.com', 'password' => bcrypt('oldpass'), 'role' => 'operator',
        ]);
        $operatorToken = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->putJson("/api/auth/merchant/team/{$staff->id}", ['name' => 'Jean Dupont'])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$operatorToken}")
            ->getJson('/api/auth/merchant/me')
            ->assertOk();
    }

    // Comble un trou de couverture laissé par la Task 4 (le middleware
    // admin.only avait été testé avant que cette route existe) : un
    // opérateur ne doit pas pouvoir lister l'équipe.
    public function test_operator_cannot_list_team(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce2@example.com', 'password' => bcrypt('secret123'),
        ]);
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean3@example.com', 'password' => 'secret', 'role' => 'operator',
        ]);
        $operatorToken = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$operatorToken}")
            ->getJson('/api/auth/merchant/team');

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Réservé à l\'administrateur.']);
    }
}
