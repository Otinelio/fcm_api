<?php

namespace Tests\Feature\Merchant;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\Restaurant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_shows_who_performed_each_operation(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('secret123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'stamps',
            'config' => ['goal' => 8],
        ]);
        $client = Client::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Ada',
            'phone' => '+22890000001', 'password' => bcrypt('secret123'),
        ]);
        $card = LoyaltyCard::create([
            'client_id' => $client->id, 'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id, 'progress' => ['stamps_current' => 0],
        ]);
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => 'x', 'role' => 'operator',
        ]);
        $operatorToken = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;
        $adminToken = $restaurant->createToken('merchant-app')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$operatorToken}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", [])
            ->assertOk();
        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", [])
            ->assertOk();

        $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->getJson("/api/merchant/clients/{$card->id}/history");

        $response->assertOk();
        $entries = $response->json('history');
        $this->assertCount(2, $entries);
        // Plus récent en premier.
        $this->assertNull($entries[0]['staff_name']);
        $this->assertSame('Jean', $entries[1]['staff_name']);
        $this->assertSame('operator', $entries[1]['staff_role']);
    }

    public function test_operator_can_also_view_history(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce2@example.com', 'password' => bcrypt('secret123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Programme', 'type' => 'stamps',
            'config' => ['goal' => 8],
        ]);
        $client = Client::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Ada',
            'phone' => '+22890000002', 'password' => bcrypt('secret123'),
        ]);
        $card = LoyaltyCard::create([
            'client_id' => $client->id, 'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id, 'progress' => ['stamps_current' => 0],
        ]);
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean2@example.com', 'password' => 'x', 'role' => 'operator',
        ]);
        $operatorToken = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$operatorToken}")
            ->getJson("/api/merchant/clients/{$card->id}/history")
            ->assertOk();
    }

    public function test_cannot_view_history_of_a_card_from_another_restaurant(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce3@example.com', 'password' => bcrypt('secret123'),
        ]);
        $otherRestaurant = Restaurant::create([
            'name' => 'Autre', 'category' => 'Restaurant',
            'email' => 'autre@example.com', 'password' => bcrypt('secret123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $otherRestaurant->id, 'name' => 'Programme', 'type' => 'stamps',
            'config' => ['goal' => 8],
        ]);
        $client = Client::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Ada',
            'phone' => '+22890000003', 'password' => bcrypt('secret123'),
        ]);
        $card = LoyaltyCard::create([
            'client_id' => $client->id, 'restaurant_id' => $otherRestaurant->id,
            'loyalty_program_id' => $program->id, 'progress' => ['stamps_current' => 0],
        ]);
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/merchant/clients/{$card->id}/history")
            ->assertStatus(403);
    }

}
