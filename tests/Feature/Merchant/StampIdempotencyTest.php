<?php

namespace Tests\Feature\Merchant;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StampIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_idempotency_key_prevents_duplicate_stamps(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa',
            'category' => 'Restaurant',
            'email' => 'awa@example.com',
            'password' => bcrypt('password123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Fidélité',
            'type' => 'stamps',
            'config' => ['goal' => 10],
        ]);
        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Jean',
            'phone' => '+22890111222',
            'password' => bcrypt('secret123'),
        ]);
        $card = LoyaltyCard::create([
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id,
            'progress' => ['stamps_current' => 2],
            'status' => 'active',
        ]);

        $token = $restaurant->createToken('merchant-app')->plainTextToken;
        $idempotencyKey = (string) Str::uuid();

        // 1er envoi
        $res1 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", [
                'idempotency_key' => $idempotencyKey,
            ]);
        $res1->assertOk();
        $this->assertEquals(3, $card->fresh()->progress['stamps_current']);

        // 2eme envoi (retry réseau avec la même idempotency_key)
        $res2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", [
                'idempotency_key' => $idempotencyKey,
            ]);
        $res2->assertOk();
        $res2->assertJsonPath('message', 'Transaction déjà traitée.');

        // Le compteur de tampons reste 3 et n'a pas été incrémenté deux fois
        $this->assertEquals(3, $card->fresh()->progress['stamps_current']);
    }
}
