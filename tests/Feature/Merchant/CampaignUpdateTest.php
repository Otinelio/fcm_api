<?php

namespace Tests\Feature\Merchant;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\NotificationCampaign;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PUT /merchant/campaigns/{campaign} — édition d'une campagne encore
 * `scheduled` (message, destinataires, date). Une campagne déjà `sent` ne
 * doit plus jamais être modifiable : les SMS sont déjà partis.
 */
class CampaignUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function restaurantWithToken(): array
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('password123'),
        ]);
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        return [$restaurant, $token];
    }

    private function clientCardFor(Restaurant $restaurant, LoyaltyProgram $program): LoyaltyCard
    {
        $client = Client::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Ada',
            'phone' => '+22890000'.random_int(1000, 9999), 'password' => bcrypt('secret123'),
        ]);

        return LoyaltyCard::create([
            'client_id' => $client->id, 'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id,
        ]);
    }

    private function scheduledCampaignFor(Restaurant $restaurant, array $clientIds): NotificationCampaign
    {
        return NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'title' => 'Campagne SMS',
            'message' => 'Message original',
            'kind' => 'manual',
            'target' => [
                'recipient_type' => 'manual',
                'recipients_count' => count($clientIds),
                'recipient_client_ids' => $clientIds,
            ],
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
        ]);
    }

    public function test_updates_message_recipients_and_schedule_of_a_scheduled_campaign(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        $a = $this->clientCardFor($restaurant, $program);
        $b = $this->clientCardFor($restaurant, $program);
        $campaign = $this->scheduledCampaignFor($restaurant, [$a->client_id]);
        $newSchedule = now()->addDays(2)->startOfMinute();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/merchant/campaigns/{$campaign->id}", [
                'message' => 'Message modifié',
                'recipient_type' => 'manual',
                'client_ids' => [$a->client_id, $b->client_id],
                'scheduled_at' => $newSchedule->toIso8601String(),
            ]);

        $response->assertOk();
        $fresh = $campaign->fresh();
        $this->assertSame('Message modifié', $fresh->message);
        $this->assertSame(2, $fresh->target['recipients_count']);
        $this->assertEqualsCanonicalizing([$a->client_id, $b->client_id], $fresh->target['recipient_client_ids']);
        $this->assertTrue($newSchedule->equalTo($fresh->scheduled_at));
    }

    public function test_cannot_update_a_campaign_already_sent(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        $a = $this->clientCardFor($restaurant, $program);
        $campaign = $this->scheduledCampaignFor($restaurant, [$a->client_id]);
        $campaign->update(['status' => 'sent', 'sent_at' => now()]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/merchant/campaigns/{$campaign->id}", [
                'message' => 'Trop tard',
                'recipient_type' => 'manual',
                'client_ids' => [$a->client_id],
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ]);

        $response->assertStatus(422);
        $this->assertSame('Message original', $campaign->fresh()->message);
    }

    public function test_cannot_update_another_restaurants_campaign(): void
    {
        [, $token] = $this->restaurantWithToken();
        $otherRestaurant = Restaurant::create([
            'name' => 'Chez Koffi', 'category' => 'Restaurant',
            'email' => 'other@example.com', 'password' => bcrypt('password123'),
        ]);
        $otherProgram = LoyaltyProgram::create([
            'restaurant_id' => $otherRestaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        $a = $this->clientCardFor($otherRestaurant, $otherProgram);
        $campaign = $this->scheduledCampaignFor($otherRestaurant, [$a->client_id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/merchant/campaigns/{$campaign->id}", [
                'message' => 'Intrus',
                'recipient_type' => 'manual',
                'client_ids' => [$a->client_id],
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ]);

        $response->assertNotFound();
    }

    public function test_recipient_ids_are_still_scoped_to_the_restaurant_on_update(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        $mine = $this->clientCardFor($restaurant, $program);
        $campaign = $this->scheduledCampaignFor($restaurant, [$mine->client_id]);

        $otherRestaurant = Restaurant::create([
            'name' => 'Chez Koffi', 'category' => 'Restaurant',
            'email' => 'other2@example.com', 'password' => bcrypt('password123'),
        ]);
        $otherProgram = LoyaltyProgram::create([
            'restaurant_id' => $otherRestaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        $notMine = $this->clientCardFor($otherRestaurant, $otherProgram);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/merchant/campaigns/{$campaign->id}", [
                'message' => 'Message modifié',
                'recipient_type' => 'manual',
                'client_ids' => [$mine->client_id, $notMine->client_id],
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ]);

        $response->assertOk();
        $this->assertSame(1, $campaign->fresh()->target['recipients_count']);
        $this->assertSame([$mine->client_id], $campaign->fresh()->target['recipient_client_ids']);
    }
}
