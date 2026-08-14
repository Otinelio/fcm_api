<?php

namespace Tests\Feature\Auth;

use App\Models\Client;
use App\Services\Auth\SocialAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Scénarios du plan d'audit client (auth + profil) — vérifie le comportement
 * réel des endpoints, pas seulement la lecture du code.
 */
class AuditScenariosTest extends TestCase
{
    use RefreshDatabase;

    private function classicClient(array $overrides = []): Client
    {
        return Client::create(array_merge([
            'uuid'       => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone'      => '+22891000001',
            'password'   => bcrypt('secret123'),
        ], $overrides));
    }

    // ── Unicité e-mail/téléphone, indépendamment de la méthode ─────────────

    public function test_register_rejects_a_phone_already_used_by_a_google_account(): void
    {
        Client::create([
            'uuid'           => (string) Str::uuid(),
            'first_name'     => 'Kofi',
            'email'          => 'kofi@example.com',
            'phone'          => '+22891000002',
            'oauth_provider' => 'google',
            'oauth_id'       => 'g-1',
        ]);

        $response = $this->postJson('/api/auth/register', [
            'first_name'            => 'Someone',
            'phone'                 => '+22891000002',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['phone']);
    }

    public function test_updating_profile_email_rejects_an_email_already_used_by_a_google_account(): void
    {
        Client::create([
            'uuid'           => (string) Str::uuid(),
            'first_name'     => 'Kofi',
            'email'          => 'taken@example.com',
            'oauth_provider' => 'google',
            'oauth_id'       => 'g-2',
        ]);
        $client = $this->classicClient(['phone' => '+22891000003']);
        $token = $client->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/profile', ['email' => 'taken@example.com']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    // ── Compte Google inexistant / existant ─────────────────────────────────

    public function test_social_login_with_action_login_and_unknown_email_gives_a_clear_not_found_message(): void
    {
        $this->partialMock(SocialAuthService::class, function ($mock) {
            $mock->shouldReceive('validateToken')->once()->andReturn([
                'sub' => 'g-unknown', 'email' => 'ghost@example.com', 'name' => 'Ghost',
            ]);
        });

        $response = $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'id_token' => 'fake',
            'action'   => 'login',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Aucun compte n\'est associé à cette adresse email.',
        ]);
    }

    public function test_social_login_with_action_login_and_existing_google_account_succeeds(): void
    {
        Client::create([
            'uuid'           => (string) Str::uuid(),
            'first_name'     => 'Kofi',
            'email'          => 'kofi3@example.com',
            'oauth_provider' => 'google',
            'oauth_id'       => 'g-3',
        ]);

        $this->partialMock(SocialAuthService::class, function ($mock) {
            $mock->shouldReceive('validateToken')->once()->andReturn([
                'sub' => 'g-3', 'email' => 'kofi3@example.com', 'name' => 'Kofi',
            ]);
        });

        $response = $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'id_token' => 'fake',
            'action'   => 'login',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['access_token', 'client']);
    }

    // ── Mauvais identifiants ────────────────────────────────────────────────

    public function test_login_with_wrong_password_gives_a_clear_message_not_a_crash(): void
    {
        $client = $this->classicClient(['phone' => '+22891000004']);

        $response = $this->postJson('/api/auth/login', [
            'phone'    => $client->phone,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Mot de passe incorrect.']);
    }

    public function test_login_with_unknown_phone_gives_a_clear_message(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'phone'    => '+22899999999',
            'password' => 'whatever123',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Aucun compte n\'est associé à ce numéro.']);
    }

    // ── Vérification / changement de mot de passe : forme de la réponse ────

    public function test_verify_password_wrong_current_password_is_a_flat_422_without_a_laravel_error_bag(): void
    {
        $client = $this->classicClient(['phone' => '+22891000005']);
        $token = $client->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/verify-password', ['current_password' => 'wrong']);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Le mot de passe est incorrect.', 'valid' => false]);
        // Confirme la forme exacte que le frontend doit gérer sans sac `errors`
        // (voir ErrorTranslator._fromValidation) : pas de clé 'errors' Laravel.
        $response->assertJsonMissing(['errors']);
    }

    public function test_change_password_wrong_current_password_is_a_flat_422_without_a_laravel_error_bag(): void
    {
        $client = $this->classicClient(['phone' => '+22891000006']);
        $token = $client->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/change-password', [
                'current_password'      => 'wrong',
                'password'              => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Le mot de passe actuel est incorrect.']);
        $response->assertJsonMissing(['errors']);
    }

    public function test_change_password_succeeds_with_correct_current_password(): void
    {
        $client = $this->classicClient(['phone' => '+22891000007', 'password' => bcrypt('oldpass123')]);
        $token = $client->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/change-password', [
                'current_password'      => 'oldpass123',
                'password'              => 'newpass456',
                'password_confirmation' => 'newpass456',
            ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('newpass456', $client->fresh()->password));
    }

    // ── Session : logout révoque uniquement le token courant ───────────────

    public function test_logout_revokes_only_the_current_token_not_other_sessions(): void
    {
        $client = $this->classicClient(['phone' => '+22891000008']);
        $tokenA = $client->createToken('device-a')->plainTextToken;
        $tokenB = $client->createToken('device-b')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/api/auth/logout')
            ->assertOk();

        // Sanctum's guard memoizes the resolved user on itself for the
        // lifetime of the guard instance; without forgetting it here, this
        // single test method would keep reusing the guard's cached user from
        // the request above no matter what Authorization header follows —
        // a Laravel testing artifact, not something that happens across real
        // HTTP requests (each gets a fresh application).
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson('/api/auth/me')
            ->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    // ── Complétion de profil incomplet ──────────────────────────────────────

    public function test_new_google_account_without_phone_is_flagged_as_needing_profile_completion(): void
    {
        $this->partialMock(SocialAuthService::class, function ($mock) {
            $mock->shouldReceive('validateToken')->once()->andReturn([
                'sub' => 'g-new', 'email' => 'newbie@example.com', 'name' => 'New Bie',
            ]);
        });

        $response = $this->postJson('/api/auth/social', [
            'provider' => 'google',
            'id_token' => 'fake',
            'action'   => 'signup',
        ]);

        $response->assertStatus(201);
        $response->assertJson(['needs_profile_completion' => true]);
    }

    // ── Expiration OTP / session de réinitialisation ────────────────────────

    public function test_reset_password_with_expired_or_unknown_token_gives_a_clear_message(): void
    {
        $client = $this->classicClient(['phone' => '+22891000009']);

        $response = $this->postJson('/api/auth/reset-password', [
            'phone'                 => $client->phone,
            'reset_token'           => 'never-issued',
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'message' => 'La session de réinitialisation a expiré. Veuillez recommencer.',
        ]);
    }

    // ── /register est maintenant limité en débit ────────────────────────────

    public function test_register_is_rate_limited_after_five_attempts_per_minute(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/register', [
                'first_name'            => 'Spammer',
                'phone'                 => '+2289100001' . $i,
                'password'              => 'password123',
                'password_confirmation' => 'password123',
            ]);
        }

        $response = $this->postJson('/api/auth/register', [
            'first_name'            => 'Spammer',
            'phone'                 => '+22891000019',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(429);
    }

    // ── change-password refuse de remettre le même mot de passe ────────────

    public function test_change_password_rejects_reusing_the_same_password(): void
    {
        $client = $this->classicClient(['phone' => '+22891000020', 'password' => bcrypt('samepass123')]);
        $token = $client->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/change-password', [
                'current_password'      => 'samepass123',
                'password'              => 'samepass123',
                'password_confirmation' => 'samepass123',
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Le nouveau mot de passe doit être différent de l\'actuel.',
        ]);
        $this->assertTrue(Hash::check('samepass123', $client->fresh()->password));
    }
}
