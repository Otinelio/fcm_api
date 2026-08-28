<?php

namespace Tests\Feature\Merchant;

use App\Events\LoyaltyCardUpdated;
use App\Events\LoyaltyRewardUpdated;
use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le client doit voir une récompense passer à "Utilisée"/"Annulée" sans
 * pull-to-refresh — voir `MivaFid-doc/recompense.md` section 13. Un seul
 * événement, réutilisé aux trois points de transition (déblocage,
 * validation, annulation), sur le canal Reverb déjà ouvert par le wallet.
 */
class RewardRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private function restaurantWithToken(?string $email = null): array
    {
        $restaurant = Restaurant::create([
            'name'     => 'Chez Awa',
            'category' => 'Restaurant',
            'email'    => $email ?? 'commerce@example.com',
            'password' => bcrypt('password123'),
        ]);
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        return [$restaurant, $token];
    }

    private function cardFor(Restaurant $restaurant, LoyaltyProgram $program): LoyaltyCard
    {
        $client = Client::create([
            'uuid'       => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone'      => '+2289000'.random_int(1000, 9999),
            'password'   => bcrypt('secret123'),
        ]);

        return LoyaltyCard::create([
            'client_id'          => $client->id,
            'restaurant_id'      => $restaurant->id,
            'loyalty_program_id' => $program->id,
            'progress'           => ['stamps_current' => 0],
        ]);
    }

    /**
     * Le dashboard marchand doit se synchroniser en direct au même titre que
     * le wallet client (historique, solde, progression, niveau) — même
     * événement, diffusé sur les deux canaux privés (`loyalty.{clientId}` +
     * `merchant.{restaurantId}`).
     */
    public function test_card_update_broadcasts_on_both_the_client_and_merchant_channels(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 10],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $event = new LoyaltyCardUpdated($card);
        $channels = $event->broadcastOn();

        $this->assertCount(2, $channels);
        $this->assertSame('private-loyalty.' . $card->client_id, $channels[0]->name);
        $this->assertSame('private-merchant.' . $restaurant->id, $channels[1]->name);
        $this->assertSame('loyalty.card.updated', $event->broadcastAs());
    }

    /** Un tampon accordé diffuse bien la mise à jour de carte vers le marchand. */
    public function test_a_stamp_broadcasts_the_card_update(): void
    {
        Event::fake([LoyaltyCardUpdated::class]);

        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Tampons', 'type' => 'stamps',
            'config' => ['goal' => 10],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        Event::assertDispatched(
            LoyaltyCardUpdated::class,
            fn (LoyaltyCardUpdated $e) => $e->card->id === $card->id
        );
    }

    /** Cashback crédité PUIS utilisé diffusent chacun leur mise à jour de carte vers le marchand. */
    public function test_cashback_earn_and_redeem_each_broadcast_the_card_update(): void
    {
        Event::fake([LoyaltyCardUpdated::class]);

        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Cashback', 'type' => 'cashback',
            'config' => ['cashback_percentage' => 10],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");

        $auth()->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 10000])->assertOk();
        $auth()->postJson("/api/merchant/clients/{$card->id}/redeem-cashback", [
            'amount_fcfa' => 500, 'redeem_amount_fcfa' => 500,
        ])->assertOk();

        Event::assertDispatched(LoyaltyCardUpdated::class, 2);
    }

    public function test_unlocking_a_reward_broadcasts_it(): void
    {
        Event::fake([LoyaltyRewardUpdated::class]);

        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 1, 'reward_description' => 'Burger offert'],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        $reward = LoyaltyReward::first();

        Event::assertDispatched(
            LoyaltyRewardUpdated::class,
            fn (LoyaltyRewardUpdated $e) => $e->reward->id === $reward->id
        );
    }

    public function test_redeeming_a_reward_broadcasts_it(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 1],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $reward = LoyaltyReward::first();

        Event::fake([LoyaltyRewardUpdated::class]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/rewards/{$reward->id}/redeem", ['token' => $reward->redeem_token])->assertOk();

        Event::assertDispatched(
            LoyaltyRewardUpdated::class,
            fn (LoyaltyRewardUpdated $e) => $e->reward->id === $reward->id && $e->reward->status === 'used'
        );
    }

    public function test_canceling_a_reward_broadcasts_it(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 1],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $reward = LoyaltyReward::first();

        Event::fake([LoyaltyRewardUpdated::class]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/rewards/{$reward->id}/cancel")->assertOk();

        Event::assertDispatched(
            LoyaltyRewardUpdated::class,
            fn (LoyaltyRewardUpdated $e) => $e->reward->id === $reward->id && $e->reward->status === 'canceled'
        );
    }

    public function test_broadcasts_on_the_clients_private_channel(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Programme',
            'type'          => 'stamps',
            'config'        => ['goal' => 1],
        ]);
        $card = $this->cardFor($restaurant, $program);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $reward = LoyaltyReward::first()->load('loyaltyCard');

        $event = new LoyaltyRewardUpdated($reward);

        $channels = $event->broadcastOn();
        $this->assertCount(2, $channels);
        $this->assertSame('private-loyalty.' . $card->client_id, $channels[0]->name);
        $this->assertSame('private-merchant.' . $restaurant->id, $channels[1]->name);
        $this->assertSame('loyalty.reward.updated', $event->broadcastAs());
        $this->assertSame(
            [
                'id'              => $reward->id,
                'loyalty_card_id' => $card->id,
                'status'          => 'available',
                'program_tier_id' => null,
                'level_name'      => null,
                'position'        => null,
                'icon_key'        => null,
            ],
            $event->broadcastWith()
        );
    }

    public function test_card_updated_broadcast_includes_tiers(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = \App\Models\LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'stamps', 'config' => [],
        ]);
        \App\Models\LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 2, 'level_name' => 'Bronze', 'reward_description' => 'Café offert',
        ]);
        \App\Models\LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 2,
            'goal' => 4, 'level_name' => 'Or', 'reward_description' => 'Menu offert',
        ]);
        $card = $this->cardFor($restaurant, $program);

        \Illuminate\Support\Facades\Event::fake([\App\Events\LoyaltyRewardUpdated::class]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\LoyaltyRewardUpdated::class, function ($event) {
            $payload = $event->broadcastWith();

            return $payload['level_name'] === 'Bronze' && $payload['position'] === 1 && $payload['program_tier_id'] !== null;
        });
    }
}
