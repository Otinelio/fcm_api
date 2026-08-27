<?php

namespace Tests\Feature\Merchant;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `DELETE /merchant/clients/{loyaltyCard}/stamps` — annule le dernier
 * tampon accordé, voir `MerchantDashboardController::removeStamp`.
 */
class RemoveStampTest extends TestCase
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

    private function cardFor(Restaurant $restaurant, LoyaltyProgram $program): LoyaltyCard
    {
        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone' => '+22890000001',
            'password' => bcrypt('secret123'),
        ]);

        return LoyaltyCard::create([
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id,
            'progress' => ['stamps_current' => 0],
        ]);
    }

    public function test_removes_last_stamp_and_restores_previous_count(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Programme',
            'type' => 'stamps',
            'config' => ['goal' => 10],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->assertSame(2, $card->fresh()->progress['stamps_current']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/merchant/clients/{$card->id}/stamps");

        $response->assertOk();
        $this->assertSame(1, $card->fresh()->progress['stamps_current']);

        // Append-only : la ligne du tampon retiré reste `valid`, c'est une
        // nouvelle ligne inverse qui journalise le retrait.
        $this->assertDatabaseHas('loyalty_transactions', [
            'loyalty_card_id' => $card->id,
            'type' => 'stamp',
            'status' => 'valid',
        ]);
        $this->assertDatabaseHas('loyalty_transactions', [
            'loyalty_card_id' => $card->id,
            'type' => 'stamp_reversal',
            'value' => -1,
            'status' => 'valid',
        ]);
    }

    public function test_history_shows_the_reversal_after_removal(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Programme',
            'type' => 'stamps',
            'config' => ['goal' => 10],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        // Historique marchand.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/merchant/clients/{$card->id}/history")
            ->assertOk()
            ->assertJsonFragment(['type' => 'stamp_reversal', 'value' => -1]);

        // Historique client (wallet mobile) — le retrait doit y être visible aussi.
        $this->app['auth']->forgetGuards();
        $clientToken = $card->client->createToken('app')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$clientToken}")
            ->getJson("/api/loyalty-cards/{$card->id}/history")
            ->assertOk()
            ->assertJsonFragment(['type' => 'stamp_reversal', 'value' => -1]);
    }

    public function test_rejects_when_no_stamp_to_remove(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Programme',
            'type' => 'stamps',
            'config' => ['goal' => 10],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/merchant/clients/{$card->id}/stamps");

        $response->assertStatus(422);
        $this->assertSame(0, $card->fresh()->progress['stamps_current']);
    }

    public function test_removing_the_stamp_that_unlocked_a_reward_cancels_it(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Programme',
            'type' => 'stamps',
            'config' => ['goal' => 1],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->assertSame('reward_available', $card->fresh()->status);
        $reward = LoyaltyReward::where('loyalty_card_id', $card->id)->first();
        $this->assertSame('available', $reward->status);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/merchant/clients/{$card->id}/stamps");

        $response->assertOk();
        $this->assertSame(0, $card->fresh()->progress['stamps_current']);
        $this->assertSame('active', $card->fresh()->status);
        $this->assertSame('canceled', $reward->fresh()->status);
    }

    public function test_blocks_removal_once_the_unlocked_reward_was_used(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Programme',
            'type' => 'stamps',
            'config' => ['goal' => 1],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $reward = LoyaltyReward::where('loyalty_card_id', $card->id)->first();
        $reward->update(['status' => 'used', 'used_at' => now()]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/merchant/clients/{$card->id}/stamps");

        $response->assertStatus(422);
        $this->assertSame(0, $card->fresh()->progress['stamps_current']);
        $this->assertSame('used', $reward->fresh()->status);
    }

    public function test_removing_a_completed_program_stamp_reopens_the_card(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Programme',
            'type' => 'stamps',
            'loops' => false,
            'config' => ['goal' => 1],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->assertNotNull($card->fresh()->completed_at);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/merchant/clients/{$card->id}/stamps");

        $response->assertOk();
        $this->assertNull($card->fresh()->completed_at);
        $this->assertSame('active', $card->fresh()->status);

        // La carte redevient progressable.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
    }

    public function test_removing_twice_undoes_two_stamps_in_a_row(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Programme',
            'type' => 'stamps',
            'config' => ['goal' => 10],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->assertSame(3, $card->fresh()->progress['stamps_current']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        $this->assertSame(1, $card->fresh()->progress['stamps_current']);
    }
}
