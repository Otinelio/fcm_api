<?php

namespace Tests\Feature;

use App\Events\LoyaltyRewardUpdated;
use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `notifications:birthdays` — remplace l'ancienne version no-op qui
 * interrogeait `App\Models\User` (jamais peuplée) au lieu de
 * `Client.birthdate`.
 */
class BirthdayRewardsTest extends TestCase
{
    use RefreshDatabase;

    private function restaurant(array $overrides = []): Restaurant
    {
        return Restaurant::create(array_merge([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('password123'),
        ], $overrides));
    }

    private function clientBornToday(array $overrides = []): Client
    {
        return Client::create(array_merge([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Ada',
            'phone' => '+2289000'.random_int(1000, 9999), 'password' => bcrypt('secret123'),
            'birthdate' => now()->subYears(25)->format('Y-m-d'),
        ], $overrides));
    }

    private function cardFor(Client $client, Restaurant $restaurant, LoyaltyProgram $program): LoyaltyCard
    {
        return LoyaltyCard::create([
            'client_id' => $client->id, 'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id,
        ]);
    }

    public function test_creates_a_birthday_reward_when_enabled(): void
    {
        $restaurant = $this->restaurant();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps',
            'config' => ['birthday_reward' => [
                'enabled' => true, 'title' => 'Dessert offert', 'validity_days' => 7,
            ]],
        ]);
        $client = $this->clientBornToday();
        $card = $this->cardFor($client, $restaurant, $program);

        Artisan::call('notifications:birthdays');

        $reward = LoyaltyReward::where('loyalty_card_id', $card->id)->first();
        $this->assertNotNull($reward);
        $this->assertSame('birthday', $reward->source);
        $this->assertSame('Dessert offert', $reward->title);
        $this->assertNotNull($reward->expires_at);
        $this->assertTrue($reward->expires_at->isSameDay(now()->addDays(7)));
    }

    public function test_no_reward_when_program_has_not_enabled_it(): void
    {
        $restaurant = $this->restaurant();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps',
            'config' => [],
        ]);
        $client = $this->clientBornToday();
        $this->cardFor($client, $restaurant, $program);

        Artisan::call('notifications:birthdays');

        $this->assertSame(0, LoyaltyReward::count());
    }

    public function test_no_reward_for_a_client_not_born_today(): void
    {
        $restaurant = $this->restaurant();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps',
            'config' => ['birthday_reward' => ['enabled' => true, 'title' => 'Dessert offert']],
        ]);
        $client = $this->clientBornToday(['birthdate' => now()->subYears(25)->subDays(3)->format('Y-m-d')]);
        $this->cardFor($client, $restaurant, $program);

        Artisan::call('notifications:birthdays');

        $this->assertSame(0, LoyaltyReward::count());
    }

    public function test_does_not_duplicate_within_the_same_year(): void
    {
        $restaurant = $this->restaurant();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps',
            'config' => ['birthday_reward' => ['enabled' => true, 'title' => 'Dessert offert']],
        ]);
        $client = $this->clientBornToday();
        $this->cardFor($client, $restaurant, $program);

        Artisan::call('notifications:birthdays');
        Artisan::call('notifications:birthdays');

        $this->assertSame(1, LoyaltyReward::count());
    }

    public function test_broadcasts_the_reward_update(): void
    {
        Event::fake([LoyaltyRewardUpdated::class]);

        $restaurant = $this->restaurant();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps',
            'config' => ['birthday_reward' => ['enabled' => true, 'title' => 'Dessert offert']],
        ]);
        $client = $this->clientBornToday();
        $card = $this->cardFor($client, $restaurant, $program);

        Artisan::call('notifications:birthdays');

        $reward = LoyaltyReward::where('loyalty_card_id', $card->id)->first();
        Event::assertDispatched(
            LoyaltyRewardUpdated::class,
            fn (LoyaltyRewardUpdated $e) => $e->reward->id === $reward->id
        );
    }

    public function test_program_store_persists_birthday_reward_config(): void
    {
        $restaurant = $this->restaurant();
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-programs', [
                'mode' => 'stamps',
                'tiers' => [['goal' => 10, 'reward_description' => 'Café offert']],
                'birthday_reward_enabled' => true,
                'birthday_reward_title' => 'Menu offert',
                'birthday_reward_validity_days' => 5,
                'color_primary' => '#4F46E5', 'color_secondary' => '#3730A3',
                'stamp_design_type' => 'check',
            ]);

        $response->assertCreated();
        $program = LoyaltyProgram::where('restaurant_id', $restaurant->id)->first();
        $this->assertTrue($program->config['birthday_reward']['enabled']);
        $this->assertSame('Menu offert', $program->config['birthday_reward']['title']);
        $this->assertSame(5, $program->config['birthday_reward']['validity_days']);
    }

    public function test_program_store_requires_title_when_birthday_reward_enabled(): void
    {
        $restaurant = $this->restaurant();
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/loyalty-programs', [
                'mode' => 'stamps',
                'tiers' => [['goal' => 10, 'reward_description' => 'Café offert']],
                'birthday_reward_enabled' => true,
                'color_primary' => '#4F46E5', 'color_secondary' => '#3730A3',
                'stamp_design_type' => 'check',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('birthday_reward_title');
    }
}
