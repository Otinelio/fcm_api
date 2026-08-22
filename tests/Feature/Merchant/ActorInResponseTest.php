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
