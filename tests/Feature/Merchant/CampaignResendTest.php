<?php

namespace Tests\Feature\Merchant;

use App\Jobs\SendCampaignNotification;
use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\NotificationCampaign;
use App\Models\NotificationLog;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignResendTest extends TestCase
{
    use RefreshDatabase;

    private function restaurantWithToken(): array
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa',
            'category' => 'Restaurant',
            'email' => 'commerce@example.com',
            'password' => bcrypt('password123'),
            'sms_credits' => 50,
        ]);
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        return [$restaurant, $token];
    }

    private function clientCardFor(Restaurant $restaurant, LoyaltyProgram $program): LoyaltyCard
    {
        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone' => '+22890000'.random_int(1000, 9999),
            'password' => bcrypt('secret123'),
        ]);

        return LoyaltyCard::create([
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id,
        ]);
    }

    public function test_cannot_schedule_campaign_in_the_past(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Programme test',
            'type' => 'stamps',
            'title' => 'Programme test',
        ]);
        $card = $this->clientCardFor($restaurant, $program);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/merchant/campaigns', [
                'type' => 'promotion',
                'title' => 'Promo passée',
                'message' => 'Offre expirée',
                'recipient_type' => 'manual',
                'client_ids' => [$card->client_id],
                'scheduled_at' => now()->subHours(2)->toIso8601String(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_at']);
    }

    public function test_resend_fails_if_sent_less_than_five_minutes_ago(): void
    {
        Queue::fake();
        [$restaurant, $token] = $this->restaurantWithToken();
        $campaign = NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'title' => 'Flash',
            'message' => 'Offre 10%',
            'kind' => 'manual',
            'status' => 'sent',
            'sent_at' => now()->subMinute(),
            'target' => [
                'recipient_type' => 'manual',
                'recipients_count' => 1,
                'recipient_client_ids' => [1],
            ],
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/campaigns/{$campaign->id}/resend", [
                'mode' => 'all',
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cette notification a été envoyée il y a moins de 5 minutes. Patientez un court instant avant de pouvoir la renvoyer.']);
    }

    public function test_resend_all_succeeds_after_cooldown(): void
    {
        Queue::fake();
        [$restaurant, $token] = $this->restaurantWithToken();
        $campaign = NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'title' => 'Flash',
            'message' => 'Offre 10%',
            'kind' => 'manual',
            'status' => 'sent',
            'sent_at' => now()->subMinutes(10),
            'target' => [
                'recipient_type' => 'manual',
                'recipients_count' => 2,
                'recipient_client_ids' => [101, 102],
            ],
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/campaigns/{$campaign->id}/resend", [
                'mode' => 'all',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['recipients_count' => 2]);

        Queue::assertPushed(SendCampaignNotification::class, 2);
        $this->assertEquals(48, $restaurant->fresh()->sms_credits);
    }

    public function test_resend_failed_only_targets_failed_logs(): void
    {
        Queue::fake();
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Fidélité',
            'type' => 'stamps',
        ]);
        $card1 = $this->clientCardFor($restaurant, $program);
        $card2 = $this->clientCardFor($restaurant, $program);

        $campaign = NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'title' => 'Flash',
            'message' => 'Offre 10%',
            'kind' => 'manual',
            'status' => 'sent',
            'sent_at' => now()->subMinutes(10),
            'target' => [
                'recipient_type' => 'manual',
                'recipients_count' => 2,
                'recipient_client_ids' => [$card1->client_id, $card2->client_id],
            ],
        ]);

        NotificationLog::create([
            'notification_campaign_id' => $campaign->id,
            'client_id' => $card1->client_id,
            'restaurant_id' => $restaurant->id,
            'channel' => 'fcm',
            'status' => 'failed',
            'failure_reason' => 'no_device_token',
        ]);

        NotificationLog::create([
            'notification_campaign_id' => $campaign->id,
            'client_id' => $card2->client_id,
            'restaurant_id' => $restaurant->id,
            'channel' => 'fcm',
            'status' => 'sent',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/campaigns/{$campaign->id}/resend", [
                'mode' => 'failed_only',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['recipients_count' => 1]);

        Queue::assertPushed(SendCampaignNotification::class, function ($job) use ($campaign, $card1) {
            return $job->campaignId === $campaign->id && $job->clientId === $card1->client_id;
        });
        $this->assertEquals(49, $restaurant->fresh()->sms_credits);
    }
}
