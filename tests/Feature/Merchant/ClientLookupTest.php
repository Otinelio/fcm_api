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
 * GET /merchant/clients/lookup?code=... — les trois façons dont le marchand
 * identifie un client pendant une validation : scan QR (`qr_token`), code
 * client affiché sur la carte (`card_code`), ou saisie manuelle du numéro
 * de téléphone. Les trois doivent résoudre la même carte.
 */
class ClientLookupTest extends TestCase
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

    private function cardFor(Restaurant $restaurant, string $phone): LoyaltyCard
    {
        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone' => $phone,
            'password' => bcrypt('secret123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'stamps',
            'loops' => true, 'config' => ['goal' => 10],
        ]);

        return LoyaltyCard::create([
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id,
            'progress' => ['stamps_current' => 0],
        ]);
    }

    public function test_lookup_by_card_code(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $card = $this->cardFor($restaurant, '+22890123456');

        $r = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients/lookup?code='.$card->card_code);
        $r->assertOk();
        $r->assertJsonPath('client.id', (string) $card->id);
    }

    public function test_lookup_by_qr_token(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $card = $this->cardFor($restaurant, '+22890123457');

        $r = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients/lookup?code='.$card->qr_token);
        $r->assertOk();
        $r->assertJsonPath('client.id', (string) $card->id);
    }

    public function test_lookup_by_exact_phone(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $card = $this->cardFor($restaurant, '+22890123458');

        $r = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients/lookup?code=+22890123458');
        $r->assertOk();
        $r->assertJsonPath('client.id', (string) $card->id);
    }

    public function test_lookup_by_phone_with_spaces_and_dashes(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $card = $this->cardFor($restaurant, '+22890123459');

        $r = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients/lookup?code='.urlencode('+228 90-12-34-59'));
        $r->assertOk();
        $r->assertJsonPath('client.id', (string) $card->id);
    }

    public function test_lookup_by_phone_without_country_code(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();
        $card = $this->cardFor($restaurant, '+22890123460');

        // Le marchand tape le numéro local, sans l'indicatif +228.
        $r = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients/lookup?code=90123460');
        $r->assertOk();
        $r->assertJsonPath('client.id', (string) $card->id);
    }

    public function test_lookup_only_matches_within_own_restaurant(): void
    {
        [$restaurantA, $tokenA] = $this->restaurantWithToken();
        $restaurantB = Restaurant::create([
            'name' => 'Chez Kofi', 'category' => 'Restaurant',
            'email' => 'kofi@example.com', 'password' => bcrypt('password123'),
        ]);
        $cardB = $this->cardFor($restaurantB, '+22890123461');

        $r = $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson('/api/merchant/clients/lookup?code=90123461');
        $r->assertNotFound();
    }

    public function test_lookup_returns_404_for_unknown_code(): void
    {
        [, $token] = $this->restaurantWithToken();

        $r = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/merchant/clients/lookup?code=INTROUVABLE');
        $r->assertNotFound();
    }
}
