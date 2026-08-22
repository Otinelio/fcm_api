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
