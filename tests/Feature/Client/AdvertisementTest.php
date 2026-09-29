<?php

namespace Tests\Feature\Client;

use App\Models\Advertisement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvertisementTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_fetch_only_active_advertisements_within_display_period(): void
    {
        // Publicité active sans dates
        $ad1 = Advertisement::create([
            'title' => 'Offre Standard',
            'subtitle' => 'PROMO',
            'description' => 'Description offre 1',
            'is_active' => true,
            'order' => 2,
        ]);

        // Publicité active avec dates valides
        $ad2 = Advertisement::create([
            'title' => 'Offre Spéciale Été',
            'subtitle' => 'TOP',
            'description' => 'Description offre 2',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(5),
            'is_active' => true,
            'order' => 1,
        ]);

        // Publicité désactivée (is_active = false)
        Advertisement::create([
            'title' => 'Offre Inactive',
            'is_active' => false,
            'order' => 0,
        ]);

        // Publicité expirée
        Advertisement::create([
            'title' => 'Offre Expirée',
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
            'is_active' => true,
            'order' => 0,
        ]);

        // Publicité future
        Advertisement::create([
            'title' => 'Offre Future',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(10),
            'is_active' => true,
            'order' => 0,
        ]);

        $response = $this->getJson('/api/client/advertisements');

        $response->assertOk();
        $response->assertJsonCount(2, 'advertisements');
        $response->assertJsonPath('advertisements.0.id', $ad2->id);
        $response->assertJsonPath('advertisements.1.id', $ad1->id);
    }

    public function test_advertisement_image_url_resolution(): void
    {
        $adWithRelative = Advertisement::create([
            'title' => 'Avec image relative',
            'image_path' => 'advertisements/sample.png',
            'is_active' => true,
        ]);

        $adWithAbsolute = Advertisement::create([
            'title' => 'Avec image externe',
            'image_path' => 'https://example.com/banner.png',
            'is_active' => true,
        ]);

        $this->assertStringContainsString('storage/advertisements/sample.png', $adWithRelative->image_url);
        $this->assertEquals('https://example.com/banner.png', $adWithAbsolute->image_url);
    }
}
