<?php

namespace Tests\Feature\Merchant;

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

    private function restaurantWithToken(): array
    {
        $restaurant = Restaurant::create([
            'name'     => 'Chez Awa',
            'category' => 'Restaurant',
            'email'    => 'commerce@example.com',
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
            'phone'      => '+22890000001',
            'password'   => bcrypt('secret123'),
        ]);

        return LoyaltyCard::create([
            'client_id'          => $client->id,
            'restaurant_id'      => $restaurant->id,
            'loyalty_program_id' => $program->id,
            'progress'           => ['stamps_current' => 0],
        ]);
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
            ->postJson("/api/merchant/rewards/{$reward->id}/redeem")->assertOk();

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
        $this->assertCount(1, $channels);
        $this->assertSame('private-loyalty.' . $card->client_id, $channels[0]->name);
        $this->assertSame('loyalty.reward.updated', $event->broadcastAs());
        $this->assertSame(
            ['id' => $reward->id, 'status' => 'available'],
            $event->broadcastWith()
        );
    }
}
