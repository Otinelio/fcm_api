<?php

namespace Tests\Feature\Auth;

use App\Models\Client;
use App\Models\Restaurant;
use App\Services\Auth\LoginThrottleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear(LoginThrottleService::accountKey('client', '+22890001122'));
        RateLimiter::clear(LoginThrottleService::accountKey('restaurant', 'merchant@example.com'));
    }

    public function test_client_login_throttles_after_five_failed_attempts(): void
    {
        $client = Client::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'first_name' => 'Jean',
            'phone' => '+22890001122',
            'password' => Hash::make('secret123'),
        ]);

        // 5 tentatives infructueuses
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'phone' => '+22890001122',
                'password' => 'mauvais_mdp',
            ]);
            $response->assertStatus(401);
        }

        // La 6ème tentative doit être bloquée par le Rate Limiter (429)
        $response = $this->postJson('/api/auth/login', [
            'phone' => '+22890001122',
            'password' => 'secret123',
        ]);
        $response->assertStatus(429);
        $this->assertTrue(LoginThrottleService::isLocked('client', '+22890001122'));
        $this->assertGreaterThan(0, LoginThrottleService::availableIn('client', '+22890001122'));

        // Déblocage via le service (simulant l'action Filament)
        LoginThrottleService::unlock('client', '+22890001122');
        $this->assertFalse(LoginThrottleService::isLocked('client', '+22890001122'));

        // La connexion doit maintenant réussir immédiatement
        $response = $this->postJson('/api/auth/login', [
            'phone' => '+22890001122',
            'password' => 'secret123',
        ]);
        $response->assertStatus(200);
        $response->assertJsonStructure(['access_token', 'client']);
    }

    public function test_restaurant_login_throttles_and_can_be_unlocked(): void
    {
        $restaurant = Restaurant::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Bistro Test',
            'email' => 'merchant@example.com',
            'password' => Hash::make('secret123'),
        ]);

        // 5 tentatives ratées
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/auth/merchant/login', [
                'email' => 'merchant@example.com',
                'password' => 'mauvais_mdp',
            ]);
            $response->assertStatus(401);
        }

        // 6ème tentative -> 429
        $response = $this->postJson('/api/auth/merchant/login', [
            'email' => 'merchant@example.com',
            'password' => 'secret123',
        ]);
        $response->assertStatus(429);
        $this->assertTrue(LoginThrottleService::isLocked('restaurant', 'merchant@example.com'));

        // Déblocage via la commande console
        $this->artisan('auth:unlock', [
            'type' => 'restaurant',
            'identifier' => 'merchant@example.com',
        ])->assertSuccessful();

        $this->assertFalse(LoginThrottleService::isLocked('restaurant', 'merchant@example.com'));

        // Connexion réussie après déblocage
        $response = $this->postJson('/api/auth/merchant/login', [
            'email' => 'merchant@example.com',
            'password' => 'secret123',
        ]);
        $response->assertStatus(200);
        $response->assertJsonStructure(['access_token', 'restaurant']);
    }

    public function test_staff_login_throttles_and_can_be_unlocked(): void
    {
        $restaurant = Restaurant::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Bistro Staff Test',
            'email' => 'bistro-staff@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $staff = \App\Models\StaffUser::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Alice Serveuse',
            'email' => 'alice@bistro.com',
            'password' => Hash::make('staff123'),
            'role' => 'operator',
            'is_active' => true,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/auth/merchant/staff/login', [
                'email' => 'alice@bistro.com',
                'password' => 'wrongpass',
            ]);
            $response->assertStatus(401);
        }

        // 6ème tentative bloquée
        $response = $this->postJson('/api/auth/merchant/staff/login', [
            'email' => 'alice@bistro.com',
            'password' => 'staff123',
        ]);
        $response->assertStatus(429);
        $this->assertTrue(LoginThrottleService::isLocked('staff', 'alice@bistro.com'));

        // Déblocage
        LoginThrottleService::unlock('staff', 'alice@bistro.com');
        $this->assertFalse(LoginThrottleService::isLocked('staff', 'alice@bistro.com'));

        // Succès après déblocage
        $response = $this->postJson('/api/auth/merchant/staff/login', [
            'email' => 'alice@bistro.com',
            'password' => 'staff123',
        ]);
        $response->assertStatus(200);
    }

    public function test_successful_login_clears_failed_attempts(): void
    {
        $client = Client::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'first_name' => 'Marc',
            'phone' => '+22890003344',
            'password' => Hash::make('secret123'),
        ]);

        // 3 tentatives ratées
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', [
                'phone' => '+22890003344',
                'password' => 'wrong',
            ]);
        }
        $this->assertEquals(3, LoginThrottleService::attempts('client', '+22890003344'));

        // Login avec bon mot de passe
        $response = $this->postJson('/api/auth/login', [
            'phone' => '+22890003344',
            'password' => 'secret123',
        ]);
        $response->assertStatus(200);

        // Le compteur doit être remis à 0
        $this->assertEquals(0, LoginThrottleService::attempts('client', '+22890003344'));
    }

    public function test_max_attempts_can_be_controlled_via_config(): void
    {
        config(['auth.throttle.max_attempts' => 2]);

        $client = Client::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'first_name' => 'Paul',
            'phone' => '+22890005566',
            'password' => Hash::make('secret123'),
        ]);

        // 2 tentatives ratées
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/auth/login', [
                'phone' => '+22890005566',
                'password' => 'wrong',
            ]);
        }

        // Avec limite à 2, la 3ème tentative est déjà bloquée (429)
        $response = $this->postJson('/api/auth/login', [
            'phone' => '+22890005566',
            'password' => 'secret123',
        ]);
        $response->assertStatus(429);
        $this->assertTrue(LoginThrottleService::isLocked('client', '+22890005566'));
    }
}

