<?php

namespace Tests\Feature\Auth;

use App\Models\Client;
use App\Services\Auth\SocialAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SocialAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_does_not_link_google_identity_onto_existing_classic_account(): void
    {
        $classic = Client::create([
            'uuid'       => (string) Str::uuid(),
            'first_name' => 'Ada',
            'email'      => 'shared@example.com',
            'phone'      => '+22890000010',
            'password'   => bcrypt('secret123'),
        ]);

        $service = app(SocialAuthService::class);

        try {
            $service->findOrCreateClient(
                provider: 'google',
                oauthId: 'google-uid-99',
                email: 'shared@example.com',
            );
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(
                'Un compte existe déjà avec cet e-mail et utilise un mot de passe. Connectez-vous avec votre mot de passe.',
                $e->getMessage(),
            );
        }

        $this->assertNull($classic->fresh()->oauth_provider);
        $this->assertNotNull($classic->fresh()->password);
    }

    public function test_creates_new_google_account_when_email_is_unused(): void
    {
        $service = app(SocialAuthService::class);

        $result = $service->findOrCreateClient(
            provider: 'google',
            oauthId: 'google-uid-100',
            email: 'fresh@example.com',
            firstName: 'Fresh',
        );

        $this->assertTrue($result['is_new']);
        $this->assertSame('google', $result['client']->oauth_provider);
    }

    public function test_finds_existing_google_account_by_oauth_id_without_touching_email_path(): void
    {
        $existing = Client::create([
            'uuid'           => (string) Str::uuid(),
            'first_name'     => 'Kofi',
            'email'          => 'kofi@example.com',
            'oauth_provider' => 'google',
            'oauth_id'       => 'google-uid-1',
        ]);

        $service = app(SocialAuthService::class);

        $result = $service->findOrCreateClient(
            provider: 'google',
            oauthId: 'google-uid-1',
            email: 'kofi@example.com',
        );

        $this->assertFalse($result['is_new']);
        $this->assertSame($existing->id, $result['client']->id);
    }

    public function test_social_login_endpoint_returns_403_when_email_belongs_to_classic_account(): void
    {
        Client::create([
            'uuid'       => (string) Str::uuid(),
            'first_name' => 'Ada',
            'email'      => 'shared2@example.com',
            'phone'      => '+22890000011',
            'password'   => bcrypt('secret123'),
        ]);

        $this->partialMock(SocialAuthService::class, function ($mock) {
            $mock->shouldReceive('validateToken')
                ->once()
                ->andReturn(['sub' => 'google-uid-200', 'email' => 'shared2@example.com', 'name' => 'Someone']);
        });

        $response = $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'id_token' => 'fake-token',
            'action'   => 'signup',
        ]);

        $response->assertStatus(403);
    }
}
