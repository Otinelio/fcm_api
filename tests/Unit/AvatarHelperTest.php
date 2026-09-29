<?php

namespace Tests\Unit;

use App\Models\Advertisement;
use App\Models\Client;
use App\Models\NotificationCampaign;
use App\Models\Restaurant;
use App\Support\AvatarHelper;
use Tests\TestCase;

class AvatarHelperTest extends TestCase
{
    public function test_extracts_initials_correctly(): void
    {
        $this->assertEquals('JD', AvatarHelper::extractInitials('Jean Dupont'));
        $this->assertEquals('BH', AvatarHelper::extractInitials('Bio Hôtel'));
        $this->assertEquals('MD', AvatarHelper::extractInitials('MASH DY'));
        $this->assertEquals('OT', AvatarHelper::extractInitials('Othnelio'));
        $this->assertEquals('?', AvatarHelper::extractInitials(null));
        $this->assertEquals('?', AvatarHelper::extractInitials('   '));
    }

    public function test_generates_valid_svg_data_uri_for_client_without_avatar(): void
    {
        $client = new Client([
            'first_name' => 'Koffi',
            'last_name' => 'Mensah',
            'avatar_url' => null,
        ]);

        $avatar = AvatarHelper::forClient($client);

        $this->assertStringStartsWith('data:image/svg+xml;utf8,', $avatar);
        $this->assertStringContainsString('KM', urldecode($avatar));
    }

    public function test_generates_valid_svg_data_uri_for_restaurant_without_logo(): void
    {
        $restaurant = new Restaurant([
            'name' => 'Chez Toto',
            'logo_url' => null,
        ]);

        $logo = AvatarHelper::forRestaurant($restaurant);

        $this->assertStringStartsWith('data:image/svg+xml;utf8,', $logo);
        $this->assertStringContainsString('CT', urldecode($logo));
    }

    public function test_generates_banner_placeholder_for_advertisement(): void
    {
        $ad = new Advertisement([
            'title' => 'Promo Spéciale',
            'image_path' => null,
        ]);

        $preview = AvatarHelper::forAdvertisement($ad);

        $this->assertStringStartsWith('data:image/svg+xml;utf8,', $preview);
    }

    public function test_generates_campaign_placeholder(): void
    {
        $campaign = new NotificationCampaign([
            'title' => 'Campagne Flash',
            'image_url' => null,
        ]);

        $preview = AvatarHelper::forCampaign($campaign);

        $this->assertStringStartsWith('data:image/svg+xml;utf8,', $preview);
    }
}
