<?php

namespace Tests\Feature\Merchant;

use App\Models\Restaurant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(string $email = 'commerce@example.com'): array
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => $email, 'password' => bcrypt('secret123'),
        ]);

        return [$restaurant, $restaurant->createToken('merchant-app')->plainTextToken];
    }

    // ─────────────────────────────────────────────────────────
    // Changement d'email
    // ─────────────────────────────────────────────────────────

    public function test_admin_can_change_the_business_email(): void
    {
        [$restaurant, $token] = $this->adminToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/email', [
                'email' => 'nouveau@example.com',
                'current_password' => 'secret123',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('restaurants', ['id' => $restaurant->id, 'email' => 'nouveau@example.com']);
    }

    public function test_email_change_requires_the_current_password(): void
    {
        [, $token] = $this->adminToken();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/email', [
                'email' => 'nouveau@example.com',
                'current_password' => 'wrongpass',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Le mot de passe actuel est incorrect.');
    }

    public function test_email_change_rejects_an_email_taken_by_another_restaurant(): void
    {
        Restaurant::create([
            'name' => 'Autre Commerce', 'category' => 'Restaurant',
            'email' => 'pris@example.com', 'password' => bcrypt('secret123'),
        ]);
        [, $token] = $this->adminToken();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/email', [
                'email' => 'pris@example.com',
                'current_password' => 'secret123',
            ])
            ->assertStatus(422);
    }

    public function test_email_change_is_admin_only(): void
    {
        [$restaurant] = $this->adminToken();
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => 'operatorpass', 'role' => 'operator',
        ]);

        $token = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/email', [
                'email' => 'nouveau@example.com',
                'current_password' => 'operatorpass',
            ])
            ->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────
    // Suppression de compte (soft delete)
    // ─────────────────────────────────────────────────────────

    public function test_account_deletion_soft_deletes_and_revokes_all_tokens(): void
    {
        [$restaurant, $token] = $this->adminToken();

        StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => 'operatorpass', 'role' => 'operator',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/auth/merchant/account', ['current_password' => 'secret123']);

        $response->assertOk();
        $this->assertSoftDeleted('restaurants', ['id' => $restaurant->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $restaurant->id]);
    }

    public function test_account_deletion_requires_the_current_password(): void
    {
        [, $token] = $this->adminToken();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/auth/merchant/account', ['current_password' => 'wrongpass'])
            ->assertStatus(422);
    }

    public function test_login_is_blocked_after_account_deletion(): void
    {
        [$restaurant] = $this->adminToken();

        $this->postJson('/api/auth/merchant/login', [
            'email' => $restaurant->email, 'password' => 'secret123',
        ])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$restaurant->createToken('t')->plainTextToken)
            ->deleteJson('/api/auth/merchant/account', ['current_password' => 'secret123'])
            ->assertOk();

        $this->postJson('/api/auth/merchant/login', [
            'email' => $restaurant->email, 'password' => 'secret123',
        ])->assertStatus(401);
    }

    public function test_staff_login_is_blocked_after_account_deletion(): void
    {
        [$restaurant, $token] = $this->adminToken();

        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => 'operatorpass', 'role' => 'operator',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/auth/merchant/account', ['current_password' => 'secret123'])
            ->assertOk();

        $this->postJson('/api/auth/merchant/staff/login', [
            'email' => $staff->email, 'password' => 'operatorpass',
        ])->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────
    // Horaires d'ouverture
    // ─────────────────────────────────────────────────────────

    private function validOpeningHours(): array
    {
        $hours = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            $closed = $day === 'sun';
            $hours[$day] = $closed
                ? ['open' => false]
                : ['open' => true, 'from' => '08:00', 'to' => '22:00'];
        }

        return $hours;
    }

    public function test_opening_hours_are_saved_and_exposed(): void
    {
        [$restaurant, $token] = $this->adminToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/profile', [
                ...array_merge($restaurant->only(['name', 'category']), ['phone' => '+22890000000']),
                'opening_hours' => $this->validOpeningHours(),
            ]);

        $response->assertOk()
            ->assertJsonPath('restaurant.opening_hours.mon.open', true);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/merchant/me')
            ->assertOk()
            ->assertJsonPath('restaurant.opening_hours.sat.to', '22:00');
    }

    public function test_opening_hours_reject_a_close_time_before_open_time(): void
    {
        [$restaurant, $token] = $this->adminToken();
        $hours = $this->validOpeningHours();
        $hours['mon']['to'] = '06:00';

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/profile', [
                ...array_merge($restaurant->only(['name', 'category']), ['phone' => '+22890000000']),
                'opening_hours' => $hours,
            ])
            ->assertStatus(422);
    }

    public function test_opening_hours_reject_missing_days(): void
    {
        [$restaurant, $token] = $this->adminToken();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/profile', [
                ...array_merge($restaurant->only(['name', 'category']), ['phone' => '+22890000000']),
                'opening_hours' => ['mon' => ['open' => false]],
            ])
            ->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────
    // Garde « dernier admin »
    // ─────────────────────────────────────────────────────────

    public function test_cannot_demote_the_last_active_admin(): void
    {
        [$restaurant, $token] = $this->adminToken();
        $admin = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Seul Admin',
            'email' => 'admin@example.com', 'password' => 'adminpass', 'role' => 'admin',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/auth/merchant/team/{$admin->id}", ['role' => 'operator'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Impossible de rétrograder le dernier administrateur actif.');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/auth/merchant/team/{$admin->id}/toggle-active", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Impossible de désactiver le dernier administrateur actif.');
    }

    public function test_can_demote_an_admin_when_another_active_admin_exists(): void
    {
        [$restaurant, $token] = $this->adminToken();
        $adminA = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Admin A',
            'email' => 'a@example.com', 'password' => 'adminpass', 'role' => 'admin',
        ]);
        StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Admin B',
            'email' => 'b@example.com', 'password' => 'adminpass', 'role' => 'admin',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/auth/merchant/team/{$adminA->id}", ['role' => 'operator'])
            ->assertOk();

        $this->assertDatabaseHas('staff_users', ['id' => $adminA->id, 'role' => 'operator']);
    }
}
