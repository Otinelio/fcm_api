<?php

namespace Tests\Unit;

use App\Services\Image\AdvertisementImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdvertisementImageServiceTest extends TestCase
{
    public function test_optimizes_and_converts_image_to_webp(): void
    {
        Storage::fake('public');

        $service = new AdvertisementImageService();

        // Créer une image factice JPEG de 1600x900 px
        $fakeImage = UploadedFile::fake()->image('test_banner.jpg', 1600, 900);

        $storedPath = $service->optimizeAndStore($fakeImage, 'advertisements', 1200, 80);

        $this->assertNotNull($storedPath);
        $this->assertStringEndsWith('.webp', $storedPath);
        Storage::disk('public')->assertExists($storedPath);

        // Vérifier que le fichier enregistré est bien lisible
        $content = Storage::disk('public')->get($storedPath);
        $this->assertNotEmpty($content);

        // Tester la suppression
        $service->deleteIfExists($storedPath);
        Storage::disk('public')->assertMissing($storedPath);
    }
}
