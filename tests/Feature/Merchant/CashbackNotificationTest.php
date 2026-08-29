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

/**
 * Notifications in-app "cashback reçu" / "changement de niveau" — nouveau
 * point de déclenchement dans `MerchantDashboardController::grantCashback`,
 * pour que le clic sur ces notifications (voir le résolveur de destination
 * Flutter) puisse ouvrir directement la carte concernée.
 */
class CashbackNotificationTest extends TestCase
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

    public function test_every_cashback_credit_records_a_cashback_received_notification(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'cashback',
            'config' => ['cashback_percentage' => 10],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 10000])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => Client::class,
            'notifiable_id' => $card->client_id,
            'type' => 'cashback_received',
        ]);
        $notification = Notification::where('type', 'cashback_received')->first();
        $this->assertSame($card->id, $notification->data['card_id']);
    }

    public function test_crossing_into_a_new_level_records_a_level_up_notification(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'cashback',
            'config' => ['cashback_percentage' => 10],
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 1000, 'level_name' => 'Argent', 'reward_description' => 'Café offert',
        ]);
        $card = $this->cardFor($restaurant, $program);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 100000])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => Client::class,
            'notifiable_id' => $card->client_id,
            'type' => 'level_up',
        ]);
        $notification = Notification::where('type', 'level_up')->first();
        $this->assertSame($card->id, $notification->data['card_id']);
        $this->assertSame('Argent', $notification->data['level_name']);
    }

    public function test_staying_within_the_same_level_does_not_repeat_level_up(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'cashback',
            'config' => ['cashback_percentage' => 10],
        ]);
        LoyaltyProgramTier::create([
            'loyalty_program_id' => $program->id, 'order' => 1,
            'goal' => 1000, 'level_name' => 'Argent', 'reward_description' => 'Café offert',
        ]);
        $card = $this->cardFor($restaurant, $program);
        $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");

        // 1er appel : franchit le palier Argent (level_up attendu).
        $auth()->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 100000])->assertOk();
        // 2e appel : toujours au même palier (déjà max) -> pas de nouveau level_up.
        $auth()->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 50000])->assertOk();

        $this->assertSame(1, Notification::where('type', 'level_up')->count());
        $this->assertSame(2, Notification::where('type', 'cashback_received')->count());
    }

    public function test_no_tiers_configured_never_records_a_level_up(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'cashback',
            'config' => ['cashback_percentage' => 10],
        ]);
        $card = $this->cardFor($restaurant, $program);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", ['amount_fcfa' => 100000])
            ->assertOk();

        $this->assertSame(0, Notification::where('type', 'level_up')->count());
        $this->assertSame(1, Notification::where('type', 'cashback_received')->count());
    }
}
