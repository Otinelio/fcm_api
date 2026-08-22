<?php

namespace Tests\Feature\Merchant;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Mirror de `AuditScenariosTest` (client) — même endpoints, côté marchand
 * (`RestaurantAuthController::verifyPassword/changePassword`), absents
 * jusqu'ici : seul le flux mot de passe oublié existait pour un marchand
 * connecté.
 */
class RestaurantChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private function restaurant(array $overrides = []): Restaurant
    {
        return Restaurant::create(array_merge([
            'name'     => 'Chez Awa',
            'category' => 'Restaurant',
            'email'    => 'commerce@example.com',
            'phone'    => '+22890000001',
            'password' => bcrypt('secret123'),
        ], $overrides));
    }

    public function test_verify_password_wrong_current_password_is_a_flat_422(): void
    {
        $restaurant = $this->restaurant();
        $token = $restaurant->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/merchant/verify-password', ['current_password' => 'wrong']);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Le mot de passe est incorrect.', 'valid' => false]);
        $response->assertJsonMissing(['errors']);
    }

    public function test_verify_password_succeeds_with_correct_current_password(): void
    {
        $restaurant = $this->restaurant(['password' => bcrypt('secret123')]);
        $token = $restaurant->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/merchant/verify-password', ['current_password' => 'secret123']);

        $response->assertOk();
        $response->assertJson(['valid' => true]);
    }

    public function test_change_password_wrong_current_password_is_a_flat_422(): void
    {
        $restaurant = $this->restaurant();
        $token = $restaurant->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/change-password', [
                'current_password'      => 'wrong',
                'password'              => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Le mot de passe actuel est incorrect.']);
        $response->assertJsonMissing(['errors']);
    }

    public function test_change_password_rejects_reusing_the_same_password(): void
    {
        $restaurant = $this->restaurant(['password' => bcrypt('secret123')]);
        $token = $restaurant->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/change-password', [
                'current_password'      => 'secret123',
                'password'              => 'secret123',
                'password_confirmation' => 'secret123',
            ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Le nouveau mot de passe doit être différent de l\'actuel.']);
    }

    public function test_change_password_succeeds_with_correct_current_password(): void
    {
        $restaurant = $this->restaurant(['password' => bcrypt('oldpass123')]);
        $token = $restaurant->createToken('t')->plainTextToken;

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
