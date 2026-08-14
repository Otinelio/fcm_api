<?php

namespace Tests\Feature\Auth;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthMethodEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function makeClassicClient(array $overrides = []): Client
    {
        return Client::create(array_merge([
            'uuid'       => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone'      => '+22890000001',
            'password'   => bcrypt('secret123'),
        ], $overrides));
    }

    private function makeGoogleClient(array $overrides = []): Client
    {
        return Client::create(array_merge([
            'uuid'           => (string) Str::uuid(),
            'first_name'     => 'Kofi',
            'email'          => 'kofi@example.com',
            'phone'          => '+22890000002',
            'password'       => null,
            'oauth_provider' => 'google',
            'oauth_id'       => 'google-uid-1',
        ], $overrides));
    }

    public function test_login_rejects_google_account_with_clear_message(): void
    {
        $client = $this->makeGoogleClient();

        $response = $this->postJson('/api/auth/login', [
            'phone'    => $client->phone,
            'password' => 'whatever123',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Ce compte utilise une connexion Google. Connectez-vous avec Google pour accéder à votre compte.',
        ]);
    }

    public function test_login_still_works_for_classic_account(): void
    {
        $client = $this->makeClassicClient();

        $response = $this->postJson('/api/auth/login', [
            'phone'    => $client->phone,
            'password' => 'secret123',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['access_token']);
    }

    public function test_forgot_password_rejects_google_account_without_sending_otp(): void
    {
        $client = $this->makeGoogleClient(['email' => 'kofi2@example.com', 'phone' => '+22890000003']);

        $response = $this->postJson('/api/auth/forgot-password', [
            'phone' => $client->phone,
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Ce compte utilise une connexion Google. Connectez-vous avec Google pour accéder à votre compte.',
        ]);
        $this->assertNull(Cache::get('otp_reset_' . $client->phone));
    }

    public function test_forgot_password_still_works_for_classic_account(): void
    {
        $client = $this->makeClassicClient(['phone' => '+22890000004']);

        $response = $this->postJson('/api/auth/forgot-password', [
            'phone' => $client->phone,
        ]);

        $response->assertOk();
        $this->assertNotNull(Cache::get('otp_reset_' . $client->phone));
    }

    public function test_reset_password_rejects_google_account_even_with_a_valid_token(): void
    {
        $client = $this->makeGoogleClient(['email' => 'kofi3@example.com', 'phone' => '+22890000005']);
        Cache::put('reset_token_' . $client->phone, 'forged-token', now()->addMinutes(15));

        $response = $this->postJson('/api/auth/reset-password', [
            'phone'                 => $client->phone,
            'reset_token'           => 'forged-token',
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(403);
        $this->assertNull($client->fresh()->password);
    }

    public function test_reset_password_still_works_for_classic_account(): void
    {
        $client = $this->makeClassicClient(['phone' => '+22890000006']);
        Cache::put('reset_token_' . $client->phone, 'real-token', now()->addMinutes(15));

        $response = $this->postJson('/api/auth/reset-password', [
            'phone'                 => $client->phone,
            'reset_token'           => 'real-token',
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertOk();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('newpassword123', $client->fresh()->password));
    }
}
