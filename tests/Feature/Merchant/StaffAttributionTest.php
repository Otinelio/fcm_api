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

class StaffAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function createFixtures(): array
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

        return [$restaurant, $card, $staff, $operatorToken, $adminToken];
    }

    public function test_stamp_granted_by_an_operator_records_their_id(): void
    {
        [, $card, $staff, $operatorToken] = $this->createFixtures();

        $this->withHeader('Authorization', "Bearer {$operatorToken}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", [])
            ->assertOk();

        $this->assertDatabaseHas('loyalty_transactions', [
            'loyalty_card_id' => $card->id, 'type' => 'stamp', 'staff_user_id' => $staff->id,
        ]);
    }

    public function test_stamp_granted_by_the_admin_records_no_staff_id(): void
    {
        [, $card, , , $adminToken] = $this->createFixtures();

        $this->withHeader('Authorization', "Bearer {$adminToken}")
            ->postJson("/api/merchant/clients/{$card->id}/stamps", [])
            ->assertOk();

        $this->assertDatabaseHas('loyalty_transactions', [
            'loyalty_card_id' => $card->id, 'type' => 'stamp', 'staff_user_id' => null,
        ]);
    }
}
