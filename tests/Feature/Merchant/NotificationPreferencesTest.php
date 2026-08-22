<?php

namespace Tests\Feature\Merchant;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function restaurantWithToken(): array
    {
        $restaurant = Restaurant::create([
            'name'     => 'Chez Awa',
            'category' => 'Restaurant',
            'email'    => 'commerce@example.com',
            'password' => bcrypt('password123'),
        ]);
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        return [$restaurant, $token];
    }

    public function test_me_returns_default_preferences_when_never_set(): void
    {
        [, $token] = $this->restaurantWithToken();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/merchant/me');

        $response->assertOk();
        $response->assertJsonPath('restaurant.notification_preferences', [
            'new_client'    => true,
            'reward'        => true,
            'low_sms'       => true,
            'weekly_report' => false,
            'promotions'    => false,
        ]);
    }

    public function test_updating_one_preference_leaves_the_others_untouched(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/notification-preferences', ['new_client' => false])
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/notification-preferences', ['weekly_report' => true])
            ->assertOk();

        $this->assertSame([
            'new_client'    => false,
            'reward'        => true,
            'low_sms'       => true,
            'weekly_report' => true,
            'promotions'    => false,
        ], $restaurant->fresh()->notification_preferences);
    }

    public function test_preferences_survive_across_requests(): void
    {
        [$restaurant, $token] = $this->restaurantWithToken();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/auth/merchant/notification-preferences', [
                'promotions'    => true,
                'low_sms'       => false,
            ])
            ->assertOk();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/merchant/me');

        $response->assertJsonPath('restaurant.notification_preferences.promotions', true);
        $response->assertJsonPath('restaurant.notification_preferences.low_sms', false);
        // Non modifiées : gardent leur défaut.
        $response->assertJsonPath('restaurant.notification_preferences.new_client', true);
    }
}
