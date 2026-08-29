<?php

namespace Tests\Feature\Merchant;

use App\Models\NotificationCampaign;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /merchant/campaigns/{campaign}/archive — masque une campagne de
 * l'historique (`GET /merchant/campaigns`) sans la supprimer : réversible,
 * juste un `archived_at` posé.
 */
class CampaignArchiveTest extends TestCase
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

    private function campaignFor(Restaurant $restaurant): NotificationCampaign
    {
        return NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'title' => 'Campagne SMS',
            'message' => 'Promo',
            'kind' => 'manual',
            'target' => ['recipient_type' => 'all', 'recipients_count' => 0, 'recipient_client_ids' => []],
            'sent_at' => now(),
            'status' => 'sent',
        ]);
    }

    public function test_archiving_a_campaign_hides_it_from_the_list(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $campaign = $this->campaignFor($restaurant);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/campaigns/{$campaign->id}/archive");

        $response->assertOk();
        $this->assertNotNull($campaign->fresh()->archived_at);

        $list = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/campaigns');
        $this->assertSame([], $list->json('campaigns'));
    }

    public function test_cannot_archive_another_restaurants_campaign(): void
    {
        [, $token] = $this->restaurantWithToken();
        $otherRestaurant = Restaurant::create([
            'name' => 'Chez Koffi', 'category' => 'Restaurant',
            'email' => 'other@example.com', 'password' => bcrypt('password123'),
        ]);
        $campaign = $this->campaignFor($otherRestaurant);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/campaigns/{$campaign->id}/archive");

        $response->assertNotFound();
        $this->assertNull($campaign->fresh()->archived_at);
    }
}
