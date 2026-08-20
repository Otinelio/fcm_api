<?php

namespace Tests\Feature\Auth;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape localisation (après step1) : le restaurant est positionné sur la
 * carte via `PUT /auth/merchant/profile`, même endpoint que step1 — voir
 * `MerchantRegistrationTest::test_step1_business_info_...` pour le chemin
 * sans coordonnées.
 */
class MerchantLocationTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedRestaurant(): array
    {
        $restaurant = Restaurant::create([
            'email'    => 'commerce@example.com',
            'password' => bcrypt('password123'),
        ]);
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        return [$restaurant, $token];
    }

    public function test_submitting_valid_coordinates_flips_has_location_and_returns_them(): void
    {
        [$restaurant, $token] = $this->authenticatedRestaurant();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/profile', [
                'name'      => 'Chez Awa',
                'category'  => 'Restaurant',
                'phone'     => '+228 90 00 00 00',
                'latitude'  => 6.1319,
                'longitude' => 1.2228,
            ]);

        $response->assertOk();
        $response->assertJsonPath('restaurant.has_location', true);
        $response->assertJsonPath('restaurant.latitude', 6.1319);
        $response->assertJsonPath('restaurant.longitude', 1.2228);

        $this->assertTrue($restaurant->fresh()->hasLocation());
    }

    public function test_latitude_out_of_range_is_rejected(): void
    {
        [, $token] = $this->authenticatedRestaurant();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/profile', [
                'name'      => 'Chez Awa',
                'category'  => 'Restaurant',
                'phone'     => '+228 90 00 00 00',
                'latitude'  => 91,
                'longitude' => 1.2228,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['latitude']);
    }

    public function test_latitude_without_longitude_is_rejected(): void
    {
        [, $token] = $this->authenticatedRestaurant();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/profile', [
                'name'     => 'Chez Awa',
                'category' => 'Restaurant',
                'phone'    => '+228 90 00 00 00',
                'latitude' => 6.1319,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['longitude']);
    }
}
