<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\Notification;
use App\Models\Referral;
use App\Models\Restaurant;
use App\Services\Fcm\FcmService;
use App\Services\Referral\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Parrainage QR par carte de fidélité — voir
 * `docs/superpowers/specs/2026-08-29-parrainage-qr-design.md`. Couvre le
 * chemin critique : join via QR de parrainage, garde-fous (auto-parrainage,
 * déjà membre), validation à la première opération (pas au scan/register),
 * unicité de la récompense, et les deux endpoints de consultation.
 */
class ReferralTest extends TestCase
{
    use RefreshDatabase;

    private function restaurantWithProgram(string $type = 'stamps', array $config = ['goal' => 10]): array
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa',
            'category' => 'Restaurant',
            'email' => 'commerce@example.com',
            'password' => bcrypt('password123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Programme',
            'type' => $type,
            'config' => $config,
        ]);

        return [$restaurant, $program];
    }

    private function clientWithToken(string $phone): array
    {
        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone' => $phone,
            'password' => bcrypt('secret123'),
        ]);
        $token = $client->createToken('mobile-app')->plainTextToken;

        return [$client, $token];
    }

    private function cardFor(Client $client, Restaurant $restaurant, LoyaltyProgram $program): LoyaltyCard
    {
        return LoyaltyCard::create([
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id,
        ]);
    }

    public function test_join_via_referral_qr_token_creates_pending_referral(): void
    {
        [$restaurant, $program] = $this->restaurantWithProgram();
        [$parrain] = $this->clientWithToken('+22890000001');
        $parrainCard = $this->cardFor($parrain, $restaurant, $program);

        [$filleul, $token] = $this->clientWithToken('+22890000002');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-cards/join', [
                'qr_token' => ReferralService::QR_PREFIX.$parrainCard->referral_qr_token,
            ]);

        $response->assertCreated();
        $filleulCard = LoyaltyCard::where('client_id', $filleul->id)->first();
        $this->assertNotNull($filleulCard);
        $this->assertDatabaseHas('referrals', [
            'restaurant_id' => $restaurant->id,
            'referrer_card_id' => $parrainCard->id,
            'referred_card_id' => $filleulCard->id,
            'status' => 'pending',
        ]);
        // Aucune récompense au simple join.
        $this->assertSame(0, LoyaltyReward::count());
    }

    public function test_join_via_referral_code_manual_entry_also_works(): void
    {
        [$restaurant, $program] = $this->restaurantWithProgram();
        [$parrain] = $this->clientWithToken('+22890000003');
        $parrainCard = $this->cardFor($parrain, $restaurant, $program);

        [, $token] = $this->clientWithToken('+22890000004');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-cards/join', ['qr_token' => $parrainCard->referral_code]);

        $response->assertCreated();
        $this->assertDatabaseHas('referrals', [
            'referrer_card_id' => $parrainCard->id,
            'status' => 'pending',
        ]);
    }

    public function test_self_referral_is_rejected(): void
    {
        [$restaurant, $program] = $this->restaurantWithProgram();
        [$client, $token] = $this->clientWithToken('+22890000005');
        $card = $this->cardFor($client, $restaurant, $program);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-cards/join', [
                'qr_token' => ReferralService::QR_PREFIX.$card->referral_qr_token,
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, Referral::count());
    }

    public function test_already_member_scanning_referral_qr_again_returns_existing_card(): void
    {
        [$restaurant, $program] = $this->restaurantWithProgram();
        [$parrain] = $this->clientWithToken('+22890000006');
        $parrainCard = $this->cardFor($parrain, $restaurant, $program);

        [$filleul, $token] = $this->clientWithToken('+22890000007');
        $existingCard = $this->cardFor($filleul, $restaurant, $program); // déjà membre

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-cards/join', [
                'qr_token' => ReferralService::QR_PREFIX.$parrainCard->referral_qr_token,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('was_recently_created', false);
        $response->assertJsonPath('card.id', $existingCard->id);
        $this->assertSame(0, Referral::count());
    }

    public function test_normal_join_via_restaurant_qr_creates_no_referral(): void
    {
        [$restaurant, $program] = $this->restaurantWithProgram();
        [, $token] = $this->clientWithToken('+22890000008');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-cards/join', ['qr_token' => $restaurant->qr_token]);

        $response->assertCreated();
        $this->assertSame(0, Referral::count());
    }

    public function test_first_stamp_validates_referral_and_rewards_the_referrer(): void
    {
        [$restaurant, $program] = $this->restaurantWithProgram();
        [$parrain] = $this->clientWithToken('+22890000009');
        $parrainCard = $this->cardFor($parrain, $restaurant, $program);

        [, $filleulToken] = $this->clientWithToken('+22890000010');
        $this->withHeader('Authorization', "Bearer {$filleulToken}")
            ->postJson('/api/loyalty-cards/join', [
                'qr_token' => ReferralService::QR_PREFIX.$parrainCard->referral_qr_token,
            ])->assertCreated();
        $filleulCard = LoyaltyCard::where('restaurant_id', $restaurant->id)
            ->where('id', '!=', $parrainCard->id)
            ->first();

        $this->app['auth']->forgetGuards();
        $merchantToken = $restaurant->createToken('merchant-app')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$merchantToken}")
            ->postJson("/api/merchant/clients/{$filleulCard->id}/stamps")
            ->assertOk();

        $this->assertDatabaseHas('referrals', [
            'referred_card_id' => $filleulCard->id,
            'status' => 'validated',
        ]);
        $reward = LoyaltyReward::where('loyalty_card_id', $parrainCard->id)->where('source', 'referral')->first();
        $this->assertNotNull($reward);

        // Une deuxième opération du filleul ne doit pas créer une deuxième récompense.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$merchantToken}")
            ->postJson("/api/merchant/clients/{$filleulCard->id}/stamps")
            ->assertOk();
        $this->assertSame(1, LoyaltyReward::where('loyalty_card_id', $parrainCard->id)->where('source', 'referral')->count());
    }

    public function test_first_cashback_operation_also_validates_referral(): void
    {
        [$restaurant, $program] = $this->restaurantWithProgram('cashback', ['cashback_percentage' => 10]);
        [$parrain] = $this->clientWithToken('+22890000011');
        $parrainCard = $this->cardFor($parrain, $restaurant, $program);

        [, $filleulToken] = $this->clientWithToken('+22890000012');
        $this->withHeader('Authorization', "Bearer {$filleulToken}")
            ->postJson('/api/loyalty-cards/join', [
                'qr_token' => ReferralService::QR_PREFIX.$parrainCard->referral_qr_token,
            ])->assertCreated();
        $filleulCard = LoyaltyCard::where('restaurant_id', $restaurant->id)
            ->where('id', '!=', $parrainCard->id)
            ->first();

        $this->app['auth']->forgetGuards();
        $merchantToken = $restaurant->createToken('merchant-app')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$merchantToken}")
            ->postJson("/api/merchant/clients/{$filleulCard->id}/stamps", ['amount_fcfa' => 1000])
            ->assertOk();

        $this->assertDatabaseHas('referrals', [
            'referred_card_id' => $filleulCard->id,
            'status' => 'validated',
        ]);
        $this->assertNotNull(
            LoyaltyReward::where('loyalty_card_id', $parrainCard->id)->where('source', 'referral')->first()
        );
    }

    public function test_client_can_list_their_own_referrals(): void
    {
        [$restaurant, $program] = $this->restaurantWithProgram();
        [$parrain, $token] = $this->clientWithToken('+22890000013');
        $parrainCard = $this->cardFor($parrain, $restaurant, $program);
        [, $filleulToken] = $this->clientWithToken('+22890000014');
        $this->withHeader('Authorization', "Bearer {$filleulToken}")
            ->postJson('/api/loyalty-cards/join', [
                'qr_token' => ReferralService::QR_PREFIX.$parrainCard->referral_qr_token,
            ])->assertCreated();

        $this->app['auth']->forgetGuards();
        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/referrals');

        $response->assertOk();
        $response->assertJsonCount(1, 'referrals');
        $response->assertJsonPath('referrals.0.status', 'pending');
    }

    public function test_merchant_can_list_restaurant_referrals(): void
    {
        [$restaurant, $program] = $this->restaurantWithProgram();
        [$parrain] = $this->clientWithToken('+22890000015');
        $parrainCard = $this->cardFor($parrain, $restaurant, $program);
        [, $filleulToken] = $this->clientWithToken('+22890000016');
        $this->withHeader('Authorization', "Bearer {$filleulToken}")
            ->postJson('/api/loyalty-cards/join', [
                'qr_token' => ReferralService::QR_PREFIX.$parrainCard->referral_qr_token,
            ])->assertCreated();

        $this->app['auth']->forgetGuards();
        $merchantToken = $restaurant->createToken('merchant-app')->plainTextToken;
        $response = $this->withHeader('Authorization', "Bearer {$merchantToken}")->getJson('/api/merchant/referrals');

        $response->assertOk();
        $response->assertJsonCount(1, 'referrals');
    }

    public function test_referrer_is_notified_when_referred_joins_and_again_when_validated(): void
    {
        [$restaurant, $program] = $this->restaurantWithProgram();
        [$parrain] = $this->clientWithToken('+22890000017');
        $parrainCard = $this->cardFor($parrain, $restaurant, $program);
        $parrain->deviceTokens()->create(['token' => 'device-token-parrain', 'platform' => 'android']);

        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldReceive('sendToToken')->twice()->andReturn(true);
        });

        [, $filleulToken] = $this->clientWithToken('+22890000018');
        $this->withHeader('Authorization', "Bearer {$filleulToken}")
            ->postJson('/api/loyalty-cards/join', [
                'qr_token' => ReferralService::QR_PREFIX.$parrainCard->referral_qr_token,
            ])->assertCreated();

        // Le simple scan/join notifie déjà A, une seule fois, sans mention de récompense.
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $parrain->getMorphClass(),
            'notifiable_id' => $parrain->id,
            'type' => 'referral_pending',
        ]);
        $this->assertSame(1, Notification::where('type', 'referral_pending')->count());

        $filleulCard = LoyaltyCard::where('restaurant_id', $restaurant->id)
            ->where('id', '!=', $parrainCard->id)
            ->first();

        $this->app['auth']->forgetGuards();
        $merchantToken = $restaurant->createToken('merchant-app')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$merchantToken}")
            ->postJson("/api/merchant/clients/{$filleulCard->id}/stamps")
            ->assertOk();

        // Première opération : une deuxième ligne in-app, distincte, "validée".
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $parrain->getMorphClass(),
            'notifiable_id' => $parrain->id,
            'type' => 'referral_validated',
        ]);

        // Une deuxième opération du filleul ne doit pas en redéclencher un troisième.
        $this->withHeader('Authorization', "Bearer {$merchantToken}")
            ->postJson("/api/merchant/clients/{$filleulCard->id}/stamps")
            ->assertOk();
        $this->assertSame(1, Notification::where('type', 'referral_validated')->count());
    }

    public function test_referred_client_receives_referral_bonus_at_join_if_configured(): void
    {
        [$restaurant, $program] = $this->restaurantWithProgram('stamps', [
            'goal' => 10,
            'referral_reward' => [
                'enabled' => true,
                'label' => 'Récompense Parrain',
                'referred_enabled' => true,
                'referred_label' => 'Récompense Filleul',
                'referred_validity_days' => 30,
            ],
        ]);
        [$parrain] = $this->clientWithToken('+22890000019');
        $parrainCard = $this->cardFor($parrain, $restaurant, $program);

        [$filleul, $filleulToken] = $this->clientWithToken('+22890000020');

        $response = $this->withHeader('Authorization', "Bearer {$filleulToken}")
            ->postJson('/api/loyalty-cards/join', [
                'qr_token' => ReferralService::QR_PREFIX.$parrainCard->referral_qr_token,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('was_recently_created', true);
        $this->assertNotNull($response->json('referral_reward_id'));

        $filleulCard = LoyaltyCard::where('client_id', $filleul->id)->first();
        $this->assertNotNull($filleulCard);

        $referralReward = LoyaltyReward::find($response->json('referral_reward_id'));
        $this->assertNotNull($referralReward);
        $this->assertSame($filleulCard->id, $referralReward->loyalty_card_id);
        $this->assertSame('referral_bonus', $referralReward->source);
        $this->assertSame('Récompense Filleul', $referralReward->title);

        $this->assertDatabaseHas('referrals', [
            'referred_card_id' => $filleulCard->id,
            'status' => 'pending',
            'referred_reward_loyalty_reward_id' => $referralReward->id,
        ]);
    }
}

