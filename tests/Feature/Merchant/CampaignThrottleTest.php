<?php

namespace Tests\Feature\Merchant;

use App\Console\Commands\DispatchScheduledCampaigns;
use App\Jobs\SendCampaignNotification;
use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\NotificationCampaign;
use App\Models\Restaurant;
use App\Services\Campaigns\CampaignThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Garde-fous d'envoi de campagnes en phase de test gratuite (pas de plan) —
 * voir `CampaignThrottle` : plage horaire 8h-20h (un envoi immédiat hors
 * plage est reprogrammé, jamais rejeté) et plafond de 200 destinataires/jour
 * par commerce.
 */
class CampaignThrottleTest extends TestCase
{
    use RefreshDatabase;

    private function restaurantWithToken(): array
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa',
            'category' => 'Restaurant',
            'email' => 'commerce@example.com',
            'password' => bcrypt('password123'),
        ]);
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        return [$restaurant, $token];
    }

    private function clientIdsFor(Restaurant $restaurant, int $count): array
    {
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $client = Client::create([
                'uuid' => (string) Str::uuid(),
                'first_name' => 'Client'.$i,
                'phone' => '+22890'.random_int(100000, 999999),
                'password' => bcrypt('secret123'),
            ]);
            LoyaltyCard::create([
                'client_id' => $client->id,
                'restaurant_id' => $restaurant->id,
                'loyalty_program_id' => $program->id,
            ]);
            $ids[] = $client->id;
        }

        return $ids;
    }

    public function test_immediate_send_within_window_sends_now(): void
    {
        Bus::fake();
        $this->travelTo(now()->setTime(14, 0));

        [$restaurant, $token] = $this->restaurantWithToken();
        $clientIds = $this->clientIdsFor($restaurant, 2);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/merchant/campaigns', [
                'message' => 'Promo',
                'type' => 'promotion',
                'recipient_type' => 'manual',
                'client_ids' => $clientIds,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('campaign.status', 'sent');
        Bus::assertDispatchedTimes(SendCampaignNotification::class, 2);
    }

    public function test_immediate_send_outside_window_is_deferred_not_rejected(): void
    {
        Bus::fake();
        $this->travelTo(now()->setTime(22, 0));

        [$restaurant, $token] = $this->restaurantWithToken();
        $clientIds = $this->clientIdsFor($restaurant, 1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/merchant/campaigns', [
                'message' => 'Promo',
                'type' => 'promotion',
                'recipient_type' => 'manual',
                'client_ids' => $clientIds,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('campaign.status', 'scheduled');
        Bus::assertNotDispatched(SendCampaignNotification::class);

        $campaign = NotificationCampaign::first();
        $this->assertSame(8, $campaign->scheduled_at->hour);
        $this->assertTrue($campaign->scheduled_at->isTomorrow());
    }

    public function test_over_daily_cap_is_rejected_for_an_immediate_send(): void
    {
        $this->travelTo(now()->setTime(10, 0));

        [$restaurant, $token] = $this->restaurantWithToken();
        $clientIds = $this->clientIdsFor($restaurant, CampaignThrottle::DAILY_RECIPIENT_CAP + 1);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/merchant/campaigns', [
                'message' => 'Promo',
                'type' => 'promotion',
                'recipient_type' => 'manual',
                'client_ids' => $clientIds,
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, NotificationCampaign::count());
    }

    public function test_scheduled_campaign_past_daily_cap_is_deferred_to_next_day_not_sent(): void
    {
        Bus::fake();
        $this->travelTo(now()->setTime(9, 0));

        [$restaurant] = $this->restaurantWithToken();
        $clientIds = $this->clientIdsFor($restaurant, CampaignThrottle::DAILY_RECIPIENT_CAP + 1);

        $campaign = NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'title' => 'Campagne SMS',
            'message' => 'Promo',
            'kind' => 'manual',
            'target' => [
                'type' => 'promotion',
                'recipient_type' => 'manual',
                'recipients_count' => count($clientIds),
                'recipient_client_ids' => $clientIds,
            ],
            'scheduled_at' => now()->subMinute(),
            'status' => 'scheduled',
        ]);

        $this->artisan(DispatchScheduledCampaigns::class)->run();

        $campaign->refresh();
        $this->assertSame('scheduled', $campaign->status);
        $this->assertTrue($campaign->scheduled_at->isTomorrow());
        Bus::assertNotDispatched(SendCampaignNotification::class);
    }
}
