<?php

namespace Tests\Feature\Merchant;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /merchant/campaigns/recipients-list — liste hydratée (nom/téléphone)
 * des destinataires d'un segment, pour la page "Destinataires" du wizard
 * de campagne SMS (recherche + tri + segmentation, cases à cocher côté app).
 */
class CampaignRecipientsListTest extends TestCase
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

    private function cardFor(
        Restaurant $restaurant,
        LoyaltyProgram $program,
        string $firstName = 'Ada',
        ?string $phone = null,
        array $overrides = [],
    ): LoyaltyCard {
        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => $firstName,
            'phone' => $phone ?? '+22890000'.random_int(1000, 9999),
            'password' => bcrypt('secret123'),
        ]);

        return LoyaltyCard::create(array_merge([
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id,
            'progress' => ['stamps_current' => 0],
        ], $overrides));
    }

    public function test_default_segment_all_returns_every_client_with_name_and_phone(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        $this->cardFor($restaurant, $program, firstName: 'Ada', phone: '+22890001111');
        $this->cardFor($restaurant, $program, firstName: 'Ben', phone: '+22890002222');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/campaigns/recipients-list');

        $response->assertOk();
        $names = collect($response->json('recipients'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Ada', 'Ben'], $names);
        $phones = collect($response->json('recipients'))->pluck('phone')->all();
        $this->assertEqualsCanonicalizing(['+22890001111', '+22890002222'], $phones);
    }

    public function test_inactive_segment_excludes_recently_active_clients(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        $recent = $this->cardFor($restaurant, $program, firstName: 'Recent');
        $stale = $this->cardFor($restaurant, $program, firstName: 'Stale');
        $recent->update(['last_activity_at' => now()->subDays(2)]);
        $stale->update(['last_activity_at' => now()->subDays(40)]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/campaigns/recipients-list?recipient_type=inactive');

        $response->assertOk();
        $names = collect($response->json('recipients'))->pluck('name')->all();
        $this->assertSame(['Stale'], $names);
    }

    public function test_search_filters_by_name_within_the_segment(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        $this->cardFor($restaurant, $program, firstName: 'Ada');
        $this->cardFor($restaurant, $program, firstName: 'Ben');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/campaigns/recipients-list?q=Ad');

        $response->assertOk();
        $names = collect($response->json('recipients'))->pluck('name')->all();
        $this->assertSame(['Ada'], $names);
    }

    public function test_recipients_are_scoped_to_the_authenticated_restaurant(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $otherRestaurant = Restaurant::create([
            'name' => 'Chez Koffi', 'category' => 'Restaurant',
            'email' => 'other@example.com', 'password' => bcrypt('password123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        $otherProgram = LoyaltyProgram::create([
            'restaurant_id' => $otherRestaurant->id, 'name' => 'P', 'type' => 'stamps', 'config' => ['goal' => 10],
        ]);
        $this->cardFor($restaurant, $program, firstName: 'Mine');
        $this->cardFor($otherRestaurant, $otherProgram, firstName: 'NotMine');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/campaigns/recipients-list');

        $response->assertOk();
        $names = collect($response->json('recipients'))->pluck('name')->all();
        $this->assertSame(['Mine'], $names);
    }
}
