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
