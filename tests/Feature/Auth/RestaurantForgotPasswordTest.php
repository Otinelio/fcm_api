<?php

namespace Tests\Feature\Auth;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `RestaurantAuthController::forgotPassword/verifyResetOtp/resetPassword`
 * acceptent désormais `phone` OU `email`, comme `ClientAuthController` — la
 * version marchand était restée email-only depuis son introduction.
 */
class RestaurantForgotPasswordTest extends TestCase
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

    public function test_forgot_password_by_email_still_works(): void
    {
        $this->restaurant();

        config(['app.debug' => true]);
        $response = $this->postJson('/api/auth/merchant/forgot-password', [
            'email' => 'commerce@example.com',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('debug_otp'));
    }

    public function test_full_reset_flow_by_phone(): void
    {
        $restaurant = $this->restaurant();

        config(['app.debug' => true]);
        $forgot = $this->postJson('/api/auth/merchant/forgot-password', [
            'phone' => '+22890000001',
        ]);
        $forgot->assertOk();
        $otp = $forgot->json('debug_otp');

        $verify = $this->postJson('/api/auth/merchant/verify-otp', [
            'phone' => '+22890000001',
            'otp'   => $otp,
        ]);
        $verify->assertOk();
        $resetToken = $verify->json('reset_token');
        $this->assertNotEmpty($resetToken);

        $reset = $this->postJson('/api/auth/merchant/reset-password', [
            'phone'                 => '+22890000001',
            'reset_token'           => $resetToken,
            'password'              => 'NewSecret123',
            'password_confirmation' => 'NewSecret123',
        ]);
        $reset->assertOk();

        $this->assertTrue(Hash::check('NewSecret123', $restaurant->fresh()->password));
    }

    public function test_forgot_password_requires_phone_or_email(): void
    {
        $response = $this->postJson('/api/auth/merchant/forgot-password', []);
        $response->assertStatus(422);
    }

    public function test_forgot_password_rejects_unknown_phone(): void
    {
        $this->restaurant();

        $response = $this->postJson('/api/auth/merchant/forgot-password', [
            'phone' => '+22899999999',
        ]);
        $response->assertStatus(422);
    }

    public function test_oauth_restaurant_cannot_use_forgot_password(): void
    {
        $this->restaurant(['oauth_provider' => 'google', 'password' => null]);

        $response = $this->postJson('/api/auth/merchant/forgot-password', [
            'email' => 'commerce@example.com',
        ]);
        $response->assertStatus(403);
    }

    public function test_reset_password_rejects_expired_or_wrong_token(): void
    {
        $this->restaurant();
        Cache::put('reset_token_merchant_+22890000001', 'the-real-token', now()->addMinutes(15));

        $response = $this->postJson('/api/auth/merchant/reset-password', [
            'phone'                 => '+22890000001',
            'reset_token'           => 'wrong-token',
            'password'              => 'NewSecret123',
            'password_confirmation' => 'NewSecret123',
        ]);
        $response->assertStatus(400);
    }
}
