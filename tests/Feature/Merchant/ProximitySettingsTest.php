<?php

namespace Tests\Feature\Merchant;

use App\Models\Restaurant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Tests\TestCase;

class ProximitySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function createRestaurant(): Restaurant
    {
        return Restaurant::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Chez Toto',
            'category' => 'Restaurant',
            'email' => 'toto@example.com',
            'phone' => '+22890000001',
            'password' => bcrypt('password123'),
            'location' => new Point(6.1319, 1.2228),
            'status' => 'active',
        ]);
    }

    public function test_merchant_can_view_proximity_settings(): void
    {
        $restaurant = $this->createRestaurant();
        Sanctum::actingAs($restaurant, ['*']);

        $response = $this->getJson('/api/merchant/proximity-settings');

        $response->assertOk()
            ->assertJsonStructure([
                'settings' => [
                    'enabled',
                    'radius_m',
                    'title',
                    'message',
                    'cooldown_hours',
                ],
                'is_active',
                'has_location',
                'latitude',
                'longitude',
                'plan_allows_geolocation',
                'cooldown_hours',
            ]);

        $this->assertFalse($response->json('settings.enabled'));
        $this->assertEquals(500, $response->json('settings.radius_m'));
        $this->assertEquals(24, $response->json('cooldown_hours'));
    }

    public function test_merchant_can_update_proximity_settings(): void
    {
        $restaurant = $this->createRestaurant();
        Sanctum::actingAs($restaurant, ['*']);

        $payload = [
            'enabled' => true,
            'radius_m' => 350,
            'title' => 'Bienvenue chez Toto !',
            'message' => 'Un cookie offert pour votre fidélité.',
        ];

        $response = $this->putJson('/api/merchant/proximity-settings', $payload);

        $response->assertOk()
            ->assertJson([
                'message' => 'Paramètres de notifications de proximité enregistrés.',
                'settings' => [
                    'enabled' => true,
                    'radius_m' => 350,
                    'title' => 'Bienvenue chez Toto !',
                    'message' => 'Un cookie offert pour votre fidélité.',
                    'cooldown_hours' => 24,
                ],
                'is_active' => true,
            ]);

        $restaurant->refresh();
        $this->assertTrue($restaurant->proximitySettings()['enabled']);
        $this->assertEquals(350, $restaurant->proximitySettings()['radius_m']);
        $this->assertEquals('Bienvenue chez Toto !', $restaurant->proximitySettings()['title']);
    }

    public function test_cannot_enable_without_title_or_message(): void
    {
        $restaurant = $this->createRestaurant();
        Sanctum::actingAs($restaurant, ['*']);

        $response = $this->putJson('/api/merchant/proximity-settings', [
            'enabled' => true,
            'radius_m' => 500,
            'title' => '',
            'message' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'message']);
    }

    public function test_radius_must_be_between_50_and_5000(): void
    {
        $restaurant = $this->createRestaurant();
        Sanctum::actingAs($restaurant, ['*']);

        $response = $this->putJson('/api/merchant/proximity-settings', [
            'enabled' => false,
            'radius_m' => 10,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['radius_m']);

        $response2 = $this->putJson('/api/merchant/proximity-settings', [
            'enabled' => false,
            'radius_m' => 10000,
        ]);

        $response2->assertStatus(422)
            ->assertJsonValidationErrors(['radius_m']);
    }
}
