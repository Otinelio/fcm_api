<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\NotificationCampaign;
use App\Models\NotificationLog;
use App\Models\Restaurant;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FilamentRestaurantQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurant_quota_calculations_are_accurate_and_consistent(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Saveurs d\'Afrique',
            'category' => 'Gastronomie',
            'email' => 'saveurs@example.com',
            'sms_credits' => 50,
            'status' => 'active',
        ]);

        $client1 = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Paul',
            'phone' => '+22997000001',
            'password' => bcrypt('secret'),
        ]);

        $client2 = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Amina',
            'phone' => '+22997000002',
            'password' => bcrypt('secret'),
        ]);

        // Campagne 1 : 2 destinataires envoyés
        $c1 = NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'type' => 'promotion',
            'title' => 'Offre Spéciale Weekend',
            'message' => 'Profitez de nos offres.',
            'status' => 'sent',
            'target' => [
                'recipient_type' => 'all',
                'recipients_count' => 2,
                'recipient_client_ids' => [$client1->id, $client2->id],
            ],
            'sent_at' => now(),
        ]);

        NotificationLog::create([
            'notification_campaign_id' => $c1->id,
            'client_id' => $client1->id,
            'restaurant_id' => $restaurant->id,
            'channel' => 'fcm',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        NotificationLog::create([
            'notification_campaign_id' => $c1->id,
            'client_id' => $client2->id,
            'restaurant_id' => $restaurant->id,
            'channel' => 'fcm',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        // Campagne 2 : Brouillon (ne doit PAS être comptabilisé dans le quota consommé)
        NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'type' => 'information',
            'title' => 'Brouillon en cours',
            'message' => 'Texte de test.',
            'status' => 'draft',
            'target' => [
                'recipient_type' => 'all',
                'recipients_count' => 10,
            ],
        ]);

        // Vérifications des métriques
        $this->assertEquals(50, $restaurant->remaining_quota);
        $this->assertEquals(2, $restaurant->consumed_quota);
        $this->assertEquals(52, $restaurant->total_quota);
        $this->assertEquals(3.8, $restaurant->quota_usage_percent);

        // Statut du service
        $status = $restaurant->notification_service_status;
        $this->assertEquals('Actif', $status['label']);
        $this->assertEquals('success', $status['color']);
    }

    public function test_restaurant_quota_handles_resent_campaigns_without_underestimation(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Burger House',
            'category' => 'Fast Food',
            'email' => 'burger@example.com',
            'sms_credits' => 30,
            'status' => 'active',
        ]);

        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Koffi',
            'phone' => '+22997000003',
            'password' => bcrypt('secret'),
        ]);

        // Campagne initiale à 1 destinataire
        $c = NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'type' => 'reward',
            'title' => 'Burger Offert',
            'message' => 'Votre fidélité récompensée.',
            'status' => 'sent',
            'target' => [
                'recipient_type' => 'custom',
                'recipients_count' => 1,
                'recipient_client_ids' => [$client->id],
            ],
            'sent_at' => now()->subDay(),
        ]);

        // Envoi 1
        NotificationLog::create([
            'notification_campaign_id' => $c->id,
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'channel' => 'fcm',
            'status' => 'sent',
            'sent_at' => now()->subDay(),
        ]);

        // Envoi 2 (renvoi / resend qui a généré un second log)
        NotificationLog::create([
            'notification_campaign_id' => $c->id,
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'channel' => 'fcm',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        // La consommation réelle doit être 2 (prise en compte du renvoi via les logs)
        $this->assertEquals(2, $restaurant->consumed_quota);
        $this->assertEquals(32, $restaurant->total_quota);
    }

    public function test_service_status_turns_critical_and_depleted_correctly(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Low Credit Cafe',
            'category' => 'Cafe',
            'email' => 'low@example.com',
            'sms_credits' => 5,
            'status' => 'active',
        ]);

        $statusLow = $restaurant->notification_service_status;
        $this->assertEquals('Critique', $statusLow['label']);
        $this->assertEquals('warning', $statusLow['color']);

        $restaurant->update(['sms_credits' => 0]);
        $statusDepleted = $restaurant->notification_service_status;
        $this->assertEquals('Épuisé', $statusDepleted['label']);
        $this->assertEquals('danger', $statusDepleted['color']);

        $restaurant->update(['status' => 'suspended']);
        $statusSuspended = $restaurant->notification_service_status;
        $this->assertEquals('Compte inactif', $statusSuspended['label']);
    }

    public function test_superadmin_can_view_restaurant_quota_page(): void
    {
        $admin = SuperAdmin::create([
            'name' => 'Admin Test',
            'email' => 'admin-quota@example.com',
            'password' => bcrypt('password123'),
        ]);

        $restaurant = Restaurant::create([
            'name' => 'Test Resto',
            'category' => 'Restaurant',
            'email' => 'test-resto@example.com',
            'sms_credits' => 100,
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'super_admins')
            ->get("/admin/restaurants/{$restaurant->id}");

        $response->assertStatus(200);
        $response->assertSee('Quota de notifications FCM');
        $response->assertSee('Historique de consommation');
        $response->assertSee('Dernières campagnes');
    }
}
