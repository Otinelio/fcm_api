<?php

namespace Tests\Feature\Merchant;

use App\Jobs\SendCampaignNotification;
use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /merchant/campaigns — la page "Destinataires" du wizard envoie
 * désormais la liste explicite des `client_ids` cochés (au lieu de laisser
 * le serveur seul dériver le segment) : le serveur doit quand même la
 * scoper au commerce authentifié avant tout envoi/décompte de crédits.
 */
class CampaignStoreTest extends TestCase
{
    use RefreshDatabase;

    private function restaurantWithToken(int $smsCredits = 100): array
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa',
            'category' => 'Restaurant',
            'email' => 'commerce@example.com',
            'password' => bcrypt('password123'),
            'sms_credits' => $smsCredits,
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
            'progress' => ['stamps_current' => 0],
        ]);
    }

    public function test_client_ids_is_required(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/merchant/campaigns', [
                'message' => 'Promo',
                'type' => 'promotion',
                'recipient_type' => 'all',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('client_ids');
    }

    public function test_client_ids_outside_the_restaurant_are_ignored_not_sent_to(): void
    {
        // Dans la plage d'envoi (8h-20h, voir `CampaignThrottle`) : sinon la
        // campagne est reprogrammée au lieu d'être envoyée immédiatement, et
        // aucun job n'est poussé pour cette assertion à le vérifier.
        $this->travelTo(now()->setTime(10, 0));
        Queue::fake();

        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        $mine = $this->clientCardFor($restaurant, $program);

        $otherRestaurant = Restaurant::create([
            'name' => 'Chez Koffi', 'category' => 'Restaurant',
            'email' => 'other@example.com', 'password' => bcrypt('password123'),
        ]);
        $otherProgram = LoyaltyProgram::create([
            'restaurant_id' => $otherRestaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        $notMine = $this->clientCardFor($otherRestaurant, $otherProgram);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/merchant/campaigns', [
                'message' => 'Promo',
                'type' => 'promotion',
                'recipient_type' => 'manual',
                'client_ids' => [$mine->client_id, $notMine->client_id],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('campaign.recipients_count', 1);

        Queue::assertPushed(SendCampaignNotification::class, function ($job) use ($mine) {
            return $job->clientId === $mine->client_id;
        });
        Queue::assertNotPushed(SendCampaignNotification::class, function ($job) use ($notMine) {
            return $job->clientId === $notMine->client_id;
        });
    }

    public function test_rejects_when_no_submitted_client_id_belongs_to_the_restaurant(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();

        $otherRestaurant = Restaurant::create([
            'name' => 'Chez Koffi', 'category' => 'Restaurant',
            'email' => 'other@example.com', 'password' => bcrypt('password123'),
        ]);
        $otherProgram = LoyaltyProgram::create([
            'restaurant_id' => $otherRestaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        $notMine = $this->clientCardFor($otherRestaurant, $otherProgram);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/merchant/campaigns', [
                'message' => 'Promo',
                'type' => 'promotion',
                'recipient_type' => 'manual',
                'client_ids' => [$notMine->client_id],
            ]);

        $response->assertStatus(422);
    }

    public function test_recipient_scoping_uses_the_scoped_count_not_the_submitted_count(): void
    {
        Queue::fake();

        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        $mine = $this->clientCardFor($restaurant, $program);

        $otherRestaurant = Restaurant::create([
            'name' => 'Chez Koffi', 'category' => 'Restaurant',
            'email' => 'other@example.com', 'password' => bcrypt('password123'),
        ]);
        $otherProgram = LoyaltyProgram::create([
            'restaurant_id' => $otherRestaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        $notMine = $this->clientCardFor($otherRestaurant, $otherProgram);

        // 2 ids soumis mais 1 seul scope au commerce : le plafond quotidien
        // (voir CampaignThrottle) et le compteur affiché doivent porter sur
        // 1 destinataire, jamais 2.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/merchant/campaigns', [
                'message' => 'Promo',
                'type' => 'promotion',
                'recipient_type' => 'manual',
                'client_ids' => [$mine->client_id, $notMine->client_id],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('campaign.recipients_count', 1);
    }
}
