<?php

namespace Tests\Feature\Merchant;

use App\Models\Restaurant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffActiveMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    // Le circuit-breaker "is_active" doit s'appliquer même sur des routes
    // qui n'appelaient jusque-là CurrentActor::resolve() pour aucune autre
    // raison (pas d'admin.only, pas d'attribution) — ici on désactive le
    // membre directement en base (sans passer par toggleActive(), qui
    // révoquerait le token lui-même) pour prouver que le token, même encore
    // valide côté Sanctum, ne suffit plus une fois le compte désactivé.
    public function test_deactivated_operator_token_is_rejected_on_client_lookup(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('secret123'),
        ]);
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => 'secret', 'role' => 'operator',
        ]);
        $token = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;

        // Toujours accessible tant que le membre est actif.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients/lookup?code=UNKNOWN')
            ->assertStatus(404);

        // Désactivation "silencieuse" (sans passer par toggleActive) : le
        // token Sanctum reste techniquement valide.
        $staff->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients/lookup?code=UNKNOWN');

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Ce compte a été désactivé. Contactez votre administrateur.']);
    }
}
