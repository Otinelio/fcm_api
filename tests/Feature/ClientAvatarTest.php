<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientAvatarTest extends TestCase
{
    use RefreshDatabase;

    private function makeClient(): Client
    {
        return Client::create([
            'uuid'       => (string) Str::uuid(),
            'first_name' => 'Ada',
            'phone'      => '+22890000001',
            'password'   => bcrypt('secret123'),
        ]);
    }

    public function test_uploads_avatar_and_stores_it_on_public_disk(): void
    {
        Storage::fake('public');
        $client = $this->makeClient();
        Sanctum::actingAs($client);

        $response = $this->postJson('/api/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
        ]);

        $response->assertOk();
        $response->assertJsonPath(
            'client.avatar_url',
            fn ($url) => is_string($url) && str_contains($url, "avatars/{$client->uuid}.jpg"),
        );
        Storage::disk('public')->assertExists("avatars/{$client->uuid}.jpg");
        $this->assertNotNull($client->fresh()->avatar_url);
    }

    public function test_rejects_non_image_file(): void
    {
        Storage::fake('public');
        $client = $this->makeClient();
        Sanctum::actingAs($client);

        $response = $this->postJson('/api/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('avatar');
    }

    public function test_rejects_oversized_file(): void
    {
        Storage::fake('public');
        $client = $this->makeClient();
        Sanctum::actingAs($client);

        $response = $this->postJson('/api/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('big.jpg', 300, 300)->size(6000),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('avatar');
    }

    public function test_rejects_image_below_minimum_dimensions(): void
    {
        Storage::fake('public');
        $client = $this->makeClient();
        Sanctum::actingAs($client);

        $response = $this->postJson('/api/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('tiny.jpg', 50, 50),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('avatar');
    }

    public function test_upload_requires_authentication(): void
    {
        Storage::fake('public');

        $response = $this->postJson('/api/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
        ]);

        $response->assertStatus(401);
    }

    public function test_reupload_with_different_extension_deletes_previous_file(): void
    {
        Storage::fake('public');
        $client = $this->makeClient();
        Sanctum::actingAs($client);

        $this->postJson('/api/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png', 300, 300),
        ])->assertOk();
        Storage::disk('public')->assertExists("avatars/{$client->uuid}.png");

        $this->postJson('/api/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
        ])->assertOk();

        Storage::disk('public')->assertExists("avatars/{$client->uuid}.jpg");
        Storage::disk('public')->assertMissing("avatars/{$client->uuid}.png");
    }

    public function test_deletes_avatar_and_resets_column(): void
    {
        Storage::fake('public');
        $client = $this->makeClient();
        Sanctum::actingAs($client);

        $this->postJson('/api/auth/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
        ])->assertOk();

        $response = $this->deleteJson('/api/auth/profile/avatar');

        $response->assertOk();
        $response->assertJsonPath('client.avatar_url', null);
        Storage::disk('public')->assertMissing("avatars/{$client->uuid}.jpg");
        $this->assertNull($client->fresh()->avatar_url);
    }

    public function test_delete_requires_authentication(): void
    {
        $response = $this->deleteJson('/api/auth/profile/avatar');
        $response->assertStatus(401);
    }
}
