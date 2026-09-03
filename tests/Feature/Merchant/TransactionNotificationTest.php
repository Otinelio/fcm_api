<?php

namespace Tests\Feature\Merchant;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyProgramTier;
use App\Models\Notification;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionNotificationTest extends TestCase
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

    private function cardFor(Restaurant $restaurant, LoyaltyProgram $program): LoyaltyCard
    {
        $client = Client::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Ada',
            'phone' => '+22890000001', 'password' => bcrypt('secret123'),
        ]);

        return LoyaltyCard::create([
            'client_id' => $client->id, 'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id,
        ]);
    }

    public function test_stamp_program_add_records_stamp_added_notification(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Carte tampon',
            'type' => 'stamp',
        ]);
        $card = $this->cardFor($restaurant, $program);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps")
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => Client::class,
            'notifiable_id' => $card->client_id,
            'type' => 'stamp_added',
        ]);
        $notification = Notification::where('type', 'stamp_added')->first();
        $this->assertSame($card->id, $notification->data['card_id']);
        $this->assertSame(1, $notification->data['value']);
        $this->assertSame('Tampon ajouté', $notification->title);
    }

    public function test_spend_program_add_records_points_added_notification(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Carte points',
            'type' => 'spend',
            'config' => ['fcfa_per_point' => 100],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 500])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => Client::class,
            'notifiable_id' => $card->client_id,
            'type' => 'points_added',
        ]);
        $notification = Notification::where('type', 'points_added')->first();
        $this->assertSame($card->id, $notification->data['card_id']);
        $this->assertSame(5, $notification->data['value']);
        $this->assertSame('Points ajoutés', $notification->title);
    }

    public function test_stamp_that_unlocks_reward_records_both_stamp_added_and_reward_unlocked(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Carte tampon',
            'type' => 'stamp', 'loops' => false,
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 2, 'level_name' => 'Palier 1', 'reward_description' => 'Café offert',
        ]);
        $card = $this->cardFor($restaurant, $program);

        // +1 tampon (pas de récompense), puis +1 (débloque)
        $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");
        $auth()->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $auth()->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        $this->assertSame(2, Notification::where('type', 'stamp_added')->count());
        $this->assertSame(1, Notification::where('type', 'reward_unlocked')->count());
    }

    public function test_stamp_program_remove_records_stamp_removed_notification(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Carte tampon',
            'type' => 'stamp',
        ]);
        $card = $this->cardFor($restaurant, $program);

        $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");
        $auth()->postJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();
        $auth()->deleteJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => Client::class,
            'notifiable_id' => $card->client_id,
            'type' => 'stamp_removed',
        ]);
        $notification = Notification::where('type', 'stamp_removed')->first();
        $this->assertSame($card->id, $notification->data['card_id']);
        $this->assertSame('Tampon retiré', $notification->title);
    }

    public function test_spend_program_remove_records_points_removed_notification(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Carte points',
            'type' => 'spend',
            'config' => ['fcfa_per_point' => 100],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");
        $auth()->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 500])->assertOk();
        $auth()->deleteJson("/api/merchant/clients/{$card->id}/stamps")->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => Client::class,
            'notifiable_id' => $card->client_id,
            'type' => 'points_removed',
        ]);
        $notification = Notification::where('type', 'points_removed')->first();
        $this->assertSame($card->id, $notification->data['card_id']);
        $this->assertSame(5, $notification->data['value']);
        $this->assertSame('Points retirés', $notification->title);
    }

    public function test_redeem_cashback_records_cashback_redeemed_notification(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'cashback',
            'config' => ['cashback_percentage' => 10],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");
        $auth()->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 10000])->assertOk();

        $auth()->postJson("/api/merchant/clients/{$card->id}/redeem-cashback", [
            'amount_fcfa' => 1000,
            'redeem_amount_fcfa' => 500,
        ])->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => Client::class,
            'notifiable_id' => $card->client_id,
            'type' => 'cashback_redeemed',
        ]);
        $notification = Notification::where('type', 'cashback_redeemed')->first();
        $this->assertSame($card->id, $notification->data['card_id']);
        $this->assertSame(500.0, (float) $notification->data['value']);
        $this->assertSame('Cashback utilisé', $notification->title);
    }

    public function test_remove_without_stamp_does_not_record_notification(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Carte',
            'type' => 'stamp',
        ]);
        $card = $this->cardFor($restaurant, $program);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/merchant/clients/{$card->id}/stamps")
            ->assertStatus(422);

        $this->assertSame(0, Notification::count());
    }
}
