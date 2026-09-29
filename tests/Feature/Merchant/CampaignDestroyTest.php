<?php

namespace Tests\Feature\Merchant;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\NotificationCampaign;
use App\Models\NotificationLog;
use App\Models\Restaurant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignDestroyTest extends TestCase
{
    use RefreshDatabase;

    private function authRestaurant(int $smsCredits = 100, ?string $email = null): array
    {
        $email = $email ?? 'test'.uniqid().'@example.com';
        $restaurant = Restaurant::create([
            'name' => 'Test Restaurant',
            'category' => 'Restaurant',
            'email' => $email,
            'password' => bcrypt('password123'),
            'sms_credits' => $smsCredits,
        ]);
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        return [$restaurant, $token];
    }

    public function test_destroy_draft_campaign_deletes_directly(): void
    {
        [$restaurant, $token] = $this->authRestaurant();

        $campaign = NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'type' => 'promo',
            'title' => 'Test Draft',
            'message' => 'Message',
            'kind' => 'manual',
            'target' => ['recipient_type' => 'all', 'recipients_count' => 5, 'recipient_client_ids' => []],
            'status' => 'draft',
        ]);

        $this->deleteJson("/api/merchant/campaigns/{$campaign->id}", [], ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJson(['message' => 'Campagne supprimée définitivement.']);

        $this->assertDatabaseMissing('notification_campaigns', ['id' => $campaign->id]);
        $restaurant->refresh();
        $this->assertEquals(100, $restaurant->sms_credits);
    }

    public function test_destroy_scheduled_campaign_restores_credits_then_deletes(): void
    {
        [$restaurant, $token] = $this->authRestaurant(50);

        $campaign = NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'type' => 'promo',
            'title' => 'Test Scheduled',
            'message' => 'Message',
            'kind' => 'manual',
            'target' => ['recipient_type' => 'all', 'recipients_count' => 10, 'recipient_client_ids' => []],
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
        ]);

        $this->deleteJson("/api/merchant/campaigns/{$campaign->id}", [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        $this->assertDatabaseMissing('notification_campaigns', ['id' => $campaign->id]);
        $restaurant->refresh();
        $this->assertEquals(60, $restaurant->sms_credits);
    }

    public function test_destroy_sent_campaign_keeps_logs_for_audit(): void
    {
        [$restaurant, $token] = $this->authRestaurant();

        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Programme Test',
            'type' => 'stamps',
            'config' => ['goal' => 10],
        ]);

        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
            'phone' => '+22890000001',
            'password' => bcrypt('password123'),
        ]);
        LoyaltyCard::create([
            'restaurant_id' => $restaurant->id,
            'client_id' => $client->id,
            'loyalty_program_id' => $program->id,
        ]);

        $campaign = NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'type' => 'promo',
            'title' => 'Test Sent',
            'message' => 'Message',
            'kind' => 'manual',
            'target' => ['recipient_type' => 'all', 'recipients_count' => 1, 'recipient_client_ids' => [$client->id]],
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        NotificationLog::create([
            'notification_campaign_id' => $campaign->id,
            'client_id' => $client->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->deleteJson("/api/merchant/campaigns/{$campaign->id}", [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        $this->assertDatabaseMissing('notification_campaigns', ['id' => $campaign->id]);
        $this->assertDatabaseHas('notification_logs', [
            'client_id' => $client->id,
            'notification_campaign_id' => null,
        ]);
    }

    public function test_destroy_archived_campaign_does_not_restore_credits_twice(): void
    {
        [$restaurant, $token] = $this->authRestaurant(50);

        // Campagne déjà archivée = crédits déjà restaurés lors de l'archivage
        $campaign = NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'type' => 'promo',
            'title' => 'Test Archived',
            'message' => 'Message',
            'kind' => 'manual',
            'target' => ['recipient_type' => 'all', 'recipients_count' => 10, 'recipient_client_ids' => []],
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
            'archived_at' => now(),
        ]);

        $this->deleteJson("/api/merchant/campaigns/{$campaign->id}", [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        $this->assertDatabaseMissing('notification_campaigns', ['id' => $campaign->id]);
        $restaurant->refresh();
        // Crédits restent à 50 (déjà restaurés à l'archivage, pas de double restauration)
        $this->assertEquals(50, $restaurant->sms_credits);
    }

    public function test_destroy_forbidden_for_other_restaurant(): void
    {
        [$restaurant1, $token1] = $this->authRestaurant(100, 'resto1@example.com');
        [$restaurant2] = $this->authRestaurant(100, 'resto2@example.com');

        $campaign = NotificationCampaign::create([
            'restaurant_id' => $restaurant2->id,
            'type' => 'promo',
            'title' => 'Other Restaurant',
            'message' => 'Message',
            'kind' => 'manual',
            'target' => ['recipient_type' => 'all', 'recipients_count' => 5, 'recipient_client_ids' => []],
            'status' => 'draft',
        ]);

        $this->deleteJson("/api/merchant/campaigns/{$campaign->id}", [], ['Authorization' => "Bearer {$token1}"])
            ->assertNotFound();
    }

    private function operatorToken(Restaurant $restaurant, ?string $email = null): string
    {
        $email = $email ?? 'operator'.uniqid().'@test.com';
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Operator',
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => 'operator',
            'is_active' => true,
        ]);

        return $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;
    }

    public function test_destroy_requires_admin_only(): void
    {
        [$restaurant, $adminToken] = $this->authRestaurant(100, 'admin@example.com');
        $operatorToken = $this->operatorToken($restaurant, 'operator_admin@example.com');

        $campaign = NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'type' => 'promo',
            'title' => 'Test',
            'message' => 'Message',
            'kind' => 'manual',
            'target' => ['recipient_type' => 'all', 'recipients_count' => 5, 'recipient_client_ids' => []],
            'status' => 'draft',
        ]);

        // Un opérateur (staff non-admin) ne peut pas supprimer
        $this->deleteJson("/api/merchant/campaigns/{$campaign->id}", [], ['Authorization' => "Bearer {$operatorToken}"])
            ->assertForbidden()
            ->assertJson(['message' => 'Réservé à l\'administrateur.']);

        // Purger le cache d'auth pour changer d'acteur
        $this->app['auth']->forgetGuards();

        // Vérifier que l'admin peut toujours supprimer
        $this->deleteJson("/api/merchant/campaigns/{$campaign->id}", [], ['Authorization' => "Bearer {$adminToken}"])
            ->assertOk();
    }
}
