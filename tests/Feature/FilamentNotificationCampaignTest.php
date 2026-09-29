<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Notification;
use App\Models\NotificationCampaign;
use App\Models\NotificationLog;
use App\Models\Restaurant;
use App\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FilamentNotificationCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_metrics_and_real_fcm_events(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Bistro Test',
            'category' => 'Restaurant',
            'email' => 'bistro-test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $client1 = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'phone' => '+22890000001',
            'password' => bcrypt('password123'),
        ]);

        $client2 = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Marie',
            'last_name' => 'Curie',
            'phone' => '+22890000002',
            'password' => bcrypt('password123'),
        ]);

        $campaign = NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'type' => 'promotion',
            'title' => 'Menu Duo Offert',
            'message' => 'Venez profiter de 50% sur votre second menu.',
            'kind' => 'manual',
            'status' => 'sent',
            'sent_at' => now(),
            'target' => [
                'recipient_type' => 'custom',
                'recipients_count' => 2,
                'recipient_client_ids' => [$client1->id, $client2->id],
            ],
        ]);

        // Log 1: Succès FCM
        $log1 = NotificationLog::create([
            'notification_campaign_id' => $campaign->id,
            'client_id' => $client1->id,
            'restaurant_id' => $restaurant->id,
            'channel' => 'fcm',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        // Log 2: Échec FCM car pas de token
        $log2 = NotificationLog::create([
            'notification_campaign_id' => $campaign->id,
            'client_id' => $client2->id,
            'restaurant_id' => $restaurant->id,
            'channel' => 'fcm',
            'status' => 'failed',
            'failure_reason' => 'no_device_token',
            'sent_at' => now(),
        ]);

        // Notification in-app lue par client 1
        Notification::create([
            'notifiable_type' => Client::class,
            'notifiable_id' => $client1->id,
            'type' => 'campaign',
            'title' => $restaurant->name,
            'body' => $campaign->title,
            'data' => ['campaign_id' => $campaign->id],
            'read_at' => now(),
        ]);

        // Notification in-app non lue pour client 2
        Notification::create([
            'notifiable_type' => Client::class,
            'notifiable_id' => $client2->id,
            'type' => 'campaign',
            'title' => $restaurant->name,
            'body' => $campaign->title,
            'data' => ['campaign_id' => $campaign->id],
            'read_at' => null,
        ]);

        // Assertions sur NotificationCampaign
        $this->assertEquals(2, $campaign->recipients_count);
        $this->assertEquals(1, $campaign->delivered_count);
        $this->assertEquals(1, $campaign->failed_count);
        $this->assertEquals(1, $campaign->read_count);
        $this->assertEquals(100.0, $campaign->read_rate);
        $this->assertEquals('Offre Spéciale', $campaign->readable_type);
        $this->assertEquals('Envoyée', $campaign->readable_status);

        // Assertions sur NotificationLog
        $this->assertTrue($log1->is_read);
        $this->assertNotNull($log1->read_at);
        $this->assertFalse($log2->is_read);
        $this->assertStringContainsString('Aucun appareil enregistré', $log2->readable_failure_reason);
    }

    public function test_super_admin_can_view_campaign_in_filament(): void
    {
        $admin = SuperAdmin::create([
            'name' => 'Admin Test',
            'email' => 'admin-test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $restaurant = Restaurant::create([
            'name' => 'Chez Luigi',
            'category' => 'Restaurant',
            'email' => 'luigi@example.com',
            'password' => bcrypt('password123'),
        ]);

        $campaign = NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'type' => 'information',
            'title' => 'Soirée Jazz',
            'message' => 'Ce vendredi à partir de 19h.',
            'kind' => 'manual',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
            'target' => [
                'recipient_type' => 'all',
                'recipients_count' => 10,
            ],
        ]);

        $response = $this->actingAs($admin, 'super_admins')
            ->get("/admin/notification-campaigns/{$campaign->id}");

        $response->assertStatus(200);
        $response->assertSee('Chez Luigi');
        $response->assertSee('Soirée Jazz');
    }

    public function test_super_admin_can_view_campaigns_list_even_when_restaurants_have_null_names(): void
    {
        $admin = SuperAdmin::create([
            'name' => 'Admin Test 2',
            'email' => 'admin-test2@example.com',
            'password' => bcrypt('password123'),
        ]);

        // Restaurant avec nom null
        Restaurant::create([
            'name' => null,
            'category' => 'Restaurant',
            'email' => 'null-name@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->actingAs($admin, 'super_admins')
            ->get('/admin/notification-campaigns');

        $response->assertStatus(200);
    }
}
