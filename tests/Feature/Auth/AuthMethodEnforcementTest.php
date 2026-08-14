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
}
