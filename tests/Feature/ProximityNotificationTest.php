<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientRestaurantGeoOptin;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Restaurant;
use App\Services\Fcm\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use MatanYadaev\EloquentSpatial\Objects\Point;
use Tests\TestCase;

class ProximityNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setupScenario(): array
    {
        // 1. Restaurant à Lomé (lat: 6.1319, lng: 1.2228) avec proximité active et rayon 500m
        $restaurant = Restaurant::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Café de Lomé',
            'category' => 'Café',
            'email' => 'cafe@example.com',
            'phone' => '+22890000002',
            'password' => bcrypt('password123'),
            'location' => new Point(6.1319, 1.2228),
            'status' => 'active',
            'proximity_settings' => [
                'enabled' => true,
                'radius_m' => 500,
                'title' => 'Bienvenue au Café de Lomé !',
                'message' => 'Passez prendre un café et cumuler vos tampons.',
                'cooldown_hours' => 24,
            ],
        ]);

        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Fidélité Café',
            'type' => 'stamps',
            'is_active' => true,
            'config' => [],
        ]);

        // 2. Client avec compte et device token
        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Koffi',
            'last_name' => 'Mensah',
            'email' => 'koffi@example.com',
            'phone' => '+22891000001',
            'password' => bcrypt('password123'),
        ]);
        $client->deviceTokens()->create([
            'token' => 'fcm-tok-koffi',
            'platform' => 'android',
        ]);

        // 3. Carte de fidélité active pour ce client chez ce restaurant
        $card = LoyaltyCard::create([
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id,
            'status' => 'active',
            'qr_code' => 'QR-KOFFI-CAFE',
        ]);

        return compact('restaurant', 'program', 'client', 'card');
    }

    public function test_entry_into_zone_sends_notification_and_sets_is_inside(): void
    {
        $data = $this->setupScenario();
        $client = $data['client'];
        $restaurant = $data['restaurant'];
        $card = $data['card'];

        $this->mock(FcmService::class, function ($mock) use ($client, $restaurant, $card) {
            $mock->shouldReceive('sendToToken')
                ->once()
                ->with(
                    'fcm-tok-koffi',
                    [
                        'title' => 'Bienvenue au Café de Lomé !',
                        'body' => 'Passez prendre un café et cumuler vos tampons.',
                    ],
                    \Mockery::on(function ($arg) use ($restaurant, $card) {
                        return isset($arg['type']) && $arg['type'] === 'proximity_alert'
                            && isset($arg['restaurant_id']) && $arg['restaurant_id'] === (string) $restaurant->id
                            && isset($arg['card_id']) && $arg['card_id'] === (string) $card->id;
                    }),
                    $client->id,
                    'proximity_alert'
                )
                ->andReturn(true);
        });

        Sanctum::actingAs($client, ['*']);

        // Position à ~150m du restaurant
        $response = $this->postJson('/api/client/location/proximity-check', [
            'latitude' => 6.1325,
            'longitude' => 1.2235,
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'events' => [
                    'notifications_sent' => 1,
                    'entries_detected' => 1,
                    'exits_detected' => 0,
                ],
            ]);

        // Vérifier l'état dans la table client_restaurant_geo_optins
        $this->assertDatabaseHas('client_restaurant_geo_optins', [
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'is_inside' => true,
        ]);

        // Vérifier l'enregistrement dans la table unifiée des notifications
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $client->getMorphClass(),
            'notifiable_id' => $client->id,
            'type' => 'proximity_alert',
            'title' => 'Bienvenue au Café de Lomé !',
        ]);
    }

    public function test_remaining_in_zone_does_not_send_duplicate_notification(): void
    {
        $data = $this->setupScenario();
        $client = $data['client'];
        $restaurant = $data['restaurant'];

        // Initialiser l'état comme déjà présent dans la zone
        ClientRestaurantGeoOptin::create([
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'opted_in' => true,
            'radius_m' => 500,
            'is_inside' => true,
            'last_entered_at' => now()->subMinutes(10),
            'last_notified_at' => now()->subMinutes(10),
        ]);

        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldNotReceive('sendToToken');
        });

        Sanctum::actingAs($client, ['*']);

        // Le client bouge un peu mais reste dans les 500m
        $response = $this->postJson('/api/client/location/proximity-check', [
            'latitude' => 6.1328,
            'longitude' => 1.2232,
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'events' => [
                    'notifications_sent' => 0,
                    'entries_detected' => 0,
                    'exits_detected' => 0,
                ],
            ]);
    }

    public function test_exit_and_reentry_before_cooldown_does_not_send_notification(): void
    {
        $data = $this->setupScenario();
        $client = $data['client'];
        $restaurant = $data['restaurant'];

        // Initialiser client qui est sorti récemment mais avec une notification il y a 2h (< 24h)
        $geoOptin = ClientRestaurantGeoOptin::create([
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'opted_in' => true,
            'radius_m' => 500,
            'is_inside' => false,
            'last_entered_at' => now()->subHours(3),
            'last_exited_at' => now()->subHours(1),
            'last_notified_at' => now()->subHours(2),
        ]);

        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldNotReceive('sendToToken');
        });

        Sanctum::actingAs($client, ['*']);

        // Le client rentre à nouveau dans la zone (< 500m)
        $response = $this->postJson('/api/client/location/proximity-check', [
            'latitude' => 6.1325,
            'longitude' => 1.2235,
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'events' => [
                    'notifications_sent' => 0,
                    'entries_detected' => 1,
                    'exits_detected' => 0,
                ],
            ]);

        // is_inside est redevenu true
        $geoOptin->refresh();
        $this->assertTrue($geoOptin->is_inside);
    }

    public function test_exit_and_reentry_after_cooldown_sends_new_notification(): void
    {
        $data = $this->setupScenario();
        $client = $data['client'];
        $restaurant = $data['restaurant'];

        // Notifié il y a 25 heures (> 24h)
        ClientRestaurantGeoOptin::create([
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'opted_in' => true,
            'radius_m' => 500,
            'is_inside' => false,
            'last_entered_at' => now()->subHours(26),
            'last_exited_at' => now()->subHours(20),
            'last_notified_at' => now()->subHours(25),
        ]);

        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldReceive('sendToToken')->once()->andReturn(true);
        });

        Sanctum::actingAs($client, ['*']);

        $response = $this->postJson('/api/client/location/proximity-check', [
            'latitude' => 6.1325,
            'longitude' => 1.2235,
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'events' => [
                    'notifications_sent' => 1,
                    'entries_detected' => 1,
                    'exits_detected' => 0,
                ],
            ]);
    }

    public function test_disabled_proximity_does_not_trigger_notification(): void
    {
        $data = $this->setupScenario();
        $client = $data['client'];
        $restaurant = $data['restaurant'];

        $restaurant->update([
            'proximity_settings' => [
                'enabled' => false,
                'radius_m' => 500,
            ],
        ]);

        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldNotReceive('sendToToken');
        });

        Sanctum::actingAs($client, ['*']);

        $response = $this->postJson('/api/client/location/proximity-check', [
            'latitude' => 6.1325,
            'longitude' => 1.2235,
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'events' => [
                    'notifications_sent' => 0,
                    'entries_detected' => 0,
                ],
            ]);
    }

    public function test_hysteresis_prevents_premature_exit_at_boundary(): void
    {
        $data = $this->setupScenario();
        $client = $data['client'];
        $restaurant = $data['restaurant'];

        // Initialiser client comme étant DÉJÀ dans la zone
        $geoOptin = ClientRestaurantGeoOptin::create([
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'opted_in' => true,
            'radius_m' => 500,
            'is_inside' => true,
            'last_entered_at' => now()->subMinutes(5),
            'last_notified_at' => now()->subMinutes(5),
        ]);

        Sanctum::actingAs($client, ['*']);

        // 1. Déplacement à ~520m (au-delà de 500m mais dans le tampon de 550m)
        // 0.0047 deg lat ~ 522m
        $response1 = $this->postJson('/api/client/location/proximity-check', [
            'latitude' => 6.1319 + 0.0047,
            'longitude' => 1.2228,
        ]);

        $response1->assertOk()
            ->assertJson([
                'status' => 'ok',
                'events' => [
                    'exits_detected' => 0, // Pas de sortie grâce à l'hystérésis
                ],
            ]);

        $geoOptin->refresh();
        $this->assertTrue($geoOptin->is_inside);

        // 2. Déplacement franc au-delà du tampon d'hystérésis (> 550m, ex. ~650m)
        // 0.006 deg lat ~ 667m
        $response2 = $this->postJson('/api/client/location/proximity-check', [
            'latitude' => 6.1319 + 0.006,
            'longitude' => 1.2228,
        ]);

        $response2->assertOk()
            ->assertJson([
                'status' => 'ok',
                'events' => [
                    'exits_detected' => 1, // Sortie confirmée
                ],
            ]);

        $geoOptin->refresh();
        $this->assertFalse($geoOptin->is_inside);
        $this->assertNotNull($geoOptin->last_exited_at);
    }
}
