<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\Notification;
use App\Models\Restaurant;
use App\Services\Fcm\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class SendBirthdayNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_records_an_in_app_birthday_notification_and_pushes(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa',
            'category' => 'Restaurant',
            'email' => 'commerce-birthday@example.com',
            'password' => bcrypt('password123'),
        ]);
        $program = LoyaltyProgram::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Programme',
            'type' => 'stamps',
            'config' => ['goal' => 10, 'birthday_reward' => ['enabled' => true, 'title' => 'Joyeux anniversaire 🎂']],
        ]);

        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone' => '+22890000104',
            'password' => bcrypt('secret123'),
            'birthdate' => now()->addDays(3)->format('Y-m-d'),
        ]);
        $client->deviceTokens()->create(['token' => 'tok-birthday', 'platform' => 'android']);

        LoyaltyCard::create([
            'client_id' => $client->id,
            'restaurant_id' => $restaurant->id,
            'loyalty_program_id' => $program->id,
        ]);

        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldReceive('sendToToken')->once()->andReturn(true);
        });

        Artisan::call('notifications:birthdays');

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $client->getMorphClass(),
            'notifiable_id' => $client->id,
            'type' => 'birthday',
        ]);
        $this->assertSame(1, Notification::where('type', 'birthday')->count());

        $reward = LoyaltyReward::where('loyalty_card_id', $client->loyaltyCards()->first()->id)
            ->where('source', 'birthday')->first();
        $this->assertSame(
            $reward->id,
            Notification::where('type', 'birthday')->first()->data['reward_id'],
        );
    }
}
