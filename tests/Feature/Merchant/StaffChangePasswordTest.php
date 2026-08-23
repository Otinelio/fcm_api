<?php

namespace Tests\Feature\Merchant;

use App\Models\Restaurant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_changing_password_does_not_touch_the_restaurant_password(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('adminpass123'),
        ]);
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => 'oldoperatorpass', 'role' => 'operator',
        ]);
        $token = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/change-password', [
                'current_password'      => 'oldoperatorpass',
                'password'              => 'newoperatorpass',
                'password_confirmation' => 'newoperatorpass',
            ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('newoperatorpass', $staff->fresh()->password));
        $this->assertTrue(Hash::check('adminpass123', $restaurant->fresh()->password));
    }

    public function test_operator_verify_password_checks_the_staff_password_not_the_restaurant_one(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce2@example.com', 'password' => bcrypt('adminpass123'),
        ]);
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean2@example.com', 'password' => 'operatorpass', 'role' => 'operator',
        ]);
        $token = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->plainTextToken;

        // Le mot de passe ADMIN ne doit pas être accepté pour l'opérateur.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/merchant/verify-password', ['current_password' => 'adminpass123'])
            ->assertStatus(422);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/merchant/verify-password', ['current_password' => 'operatorpass'])
            ->assertOk();
    }

    public function test_admin_change_password_still_works_unchanged(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce3@example.com', 'password' => bcrypt('oldpass123'),
        ]);
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/change-password', [
                'current_password'      => 'oldpass123',
                'password'              => 'newpass456',
                'password_confirmation' => 'newpass456',
            ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('newpass456', $restaurant->fresh()->password));
    }
}
