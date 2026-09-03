<?php

namespace Tests\Feature;

use App\Jobs\SendCampaignNotification;
use App\Models\Client;
use App\Models\NotificationCampaign;
use App\Models\Restaurant;
use App\Services\Fcm\FcmService;
use App\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SendCampaignNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_records_an_in_app_notification_in_addition_to_the_existing_push_and_log(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa',
            'category' => 'Restaurant',
            'email' => 'commerce-campaign@example.com',
            'password' => bcrypt('password123'),
        ]);
        $client = Client::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone' => '+22890000105',
            'password' => bcrypt('secret123'),
        ]);
        $client->deviceTokens()->create(['token' => 'tok-campaign', 'platform' => 'android']);

        $campaign = NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'title' => 'Campagne SMS',
            'message' => 'Weekend -20% !',
            'target' => ['recipient_type' => 'all'],
            'status' => 'sent',
        ]);

        $this->mock(FcmService::class, function ($mock) {
            $mock->shouldReceive('sendToToken')->once()->andReturn(true);
        });

        (new SendCampaignNotification($campaign->id, $client->id))
            ->handle(app(FcmService::class), app(NotificationDispatcher::class));

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $client->getMorphClass(),
            'notifiable_id' => $client->id,
            'type' => 'campaign',
            'title' => 'Chez Awa',
            'body' => 'Campagne SMS — Weekend -20% !',
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'notification_campaign_id' => $campaign->id,
            'client_id' => $client->id,
            'status' => 'sent',
        ]);
    }
}
