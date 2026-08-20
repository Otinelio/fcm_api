<?php

namespace Tests\Feature\Auth;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chemin nominal de l'inscription marchande : jusqu'ici seul l'écran
 * `MerchantAuthScreen` testait manuellement ce parcours, aucune couverture
 * automatisée n'existait côté API (contrairement au client).
 */
class MerchantRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_the_restaurant_and_returns_a_usable_token(): void
    {
        $response = $this->postJson('/api/auth/merchant/register', [
            'email'    => 'nouveau-commerce@example.com',
            'password' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'access_token',
            'token_type',
            'restaurant' => ['id', 'email'],
        ]);

        $this->assertDatabaseHas('restaurants', [
            'email' => 'nouveau-commerce@example.com',
        ]);

        $restaurant = Restaurant::where('email', 'nouveau-commerce@example.com')->first();
        $this->assertFalse($restaurant->hasBusinessInfo());

        $token = $response->json('access_token');
        $me = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/auth/merchant/me');
        $me->assertOk();
        $me->assertJsonPath('restaurant.has_business_info', false);
    }

    public function test_register_rejects_a_password_shorter_than_eight_characters(): void
    {
        $response = $this->postJson('/api/auth/merchant/register', [
            'email'    => 'faible@example.com',
            'password' => 'short1',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_step1_business_info_completes_registration_and_flips_has_business_info(): void
    {
        $restaurant = Restaurant::create([
            'email'    => 'commerce@example.com',
            'password' => bcrypt('password123'),
        ]);
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        $this->assertFalse($restaurant->hasBusinessInfo());

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/profile', [
                'name'     => 'Chez Awa',
                'category' => 'Restaurant',
                'phone'    => '+228 90 00 00 00',
            ]);

        $response->assertOk();
        $this->assertTrue($restaurant->fresh()->hasBusinessInfo());
    }
}
