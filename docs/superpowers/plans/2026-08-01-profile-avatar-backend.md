# Profile Avatar API (Backend) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an authenticated client upload or remove a profile photo via two new API endpoints, storing the file on Laravel's local `public` disk.

**Architecture:** Two new routes on the existing `/api/auth` group (`POST /profile/avatar`, `DELETE /profile/avatar`), guarded by the same `auth:sanctum` middleware as the other profile routes. A new `UpdateAvatarRequest` FormRequest validates the upload; two new methods on the existing `ClientAuthController` handle storage and respond with the same `clientData()` shape already used by `updateProfile`.

**Tech Stack:** Laravel 13, Sanctum, `Storage` facade (`public` disk), PHPUnit (Feature tests), in-memory SQLite for tests.

**Spec:** `docs/superpowers/specs/2026-08-01-profile-avatar-design.md`

## Global Constraints

- Storage path is deterministic: `avatars/{client_uuid}.{ext}` — a re-upload overwrites (any stale file with a *different* extension from a previous upload must be deleted first, since the extension can change between uploads).
- Validation: `avatar` field, `required`, `image`, `mimes:jpg,jpeg,png,webp`, `max:5120` (5 MB), `dimensions:min_width=200,min_height=200`.
- `avatar_url` stored in the `clients` table must be an **absolute** URL (`asset(Storage::url($path))`), matching the shape already returned by `clientData()` for other fields.
- Both endpoints live inside the existing `Route::middleware('auth:sanctum')->group(...)` block in `routes/api.php` (same block as `/profile`, `/logout`, etc.) — do not create a new middleware group.
- Response shape for both endpoints: `{ "message": "...", "client": { ...same fields as clientData()... } }`.

---

### Task 1: Avatar upload & delete endpoints

**Files:**
- Create: `app/Http/Requests/Auth/UpdateAvatarRequest.php`
- Modify: `app/Http/Controllers/Api/ClientAuthController.php` (add `use Illuminate\Support\Facades\Storage;` import, add `uploadAvatar()` and `deleteAvatar()` methods near `updateProfile()` at line ~254-265)
- Modify: `routes/api.php:33-34` (add two routes inside the existing `auth:sanctum` group)
- Test: `tests/Feature/ClientAvatarTest.php`

**Interfaces:**
- Consumes: `Client::$uuid`, `Client::$avatar_url` (existing model fields), `ClientAuthController::clientData(Client $client): array` (existing private helper — reuse it verbatim, do not duplicate its field list)
- Produces: `POST /api/auth/profile/avatar` and `DELETE /api/auth/profile/avatar`, both returning `{ message: string, client: array }` where `client` is exactly `clientData()`'s output — later frontend tasks depend on this exact shape (`client.avatar_url` is either an absolute URL string or `null`)

**Local infra prerequisite (run once, not committed — `public/storage` is gitignored):**

```bash
php artisan storage:link
```

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ClientAvatarTest.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ClientAvatarTest`
Expected: FAIL — routes `/api/auth/profile/avatar` don't exist yet (404 responses instead of the asserted status codes).

- [ ] **Step 3: Create the validation request**

Create `app/Http/Requests/Auth/UpdateAvatarRequest.php`:

```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
                'dimensions:min_width=200,min_height=200',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.required'   => 'Une photo est requise.',
            'avatar.image'      => 'Le fichier doit être une image.',
            'avatar.mimes'      => 'Formats acceptés : JPG, PNG, WEBP.',
            'avatar.max'        => 'L\'image ne doit pas dépasser 5 Mo.',
            'avatar.dimensions' => 'L\'image doit faire au moins 200x200 pixels.',
        ];
    }
}
```

- [ ] **Step 4: Add the controller methods**

In `app/Http/Controllers/Api/ClientAuthController.php`, add the import alongside the existing ones (near line 10):

```php
use App\Http\Requests\Auth\UpdateAvatarRequest;
use Illuminate\Support\Facades\Storage;
```

Then add these two methods immediately after `updateProfile()` (after line 265, before the `// Logout` section comment):

```php
    // ─────────────────────────────────────────────────────────
    // Avatar
    // ─────────────────────────────────────────────────────────

    /**
     * POST /api/auth/profile/avatar
     *
     * Upload (ou remplace) la photo de profil du client.
     */
    public function uploadAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user();

        // Un ré-upload peut changer de format (png -> jpg) : on nettoie toute
        // ancienne extension avant d'écrire la nouvelle, sinon l'ancien fichier
        // reste orphelin sur le disque.
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            Storage::disk('public')->delete("avatars/{$client->uuid}.{$ext}");
        }

        $extension = $request->file('avatar')->extension();
        $path = $request->file('avatar')->storeAs('avatars', "{$client->uuid}.{$extension}", 'public');

        $client->update(['avatar_url' => asset(Storage::url($path))]);

        return response()->json([
            'message' => 'Photo de profil mise à jour.',
            'client'  => $this->clientData($client->fresh()),
        ]);
    }

    /**
     * DELETE /api/auth/profile/avatar
     *
     * Supprime la photo de profil du client (retour à l'avatar par défaut).
     */
    public function deleteAvatar(\Illuminate\Http\Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user();

        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            Storage::disk('public')->delete("avatars/{$client->uuid}.{$ext}");
        }
        $client->update(['avatar_url' => null]);

        return response()->json([
            'message' => 'Photo de profil supprimée.',
            'client'  => $this->clientData($client->fresh()),
        ]);
    }
```

- [ ] **Step 5: Add the routes**

In `routes/api.php`, inside the `auth:sanctum` group (line 31-38), add two lines right after the `/profile` route (line 33):

```php
        Route::put('/profile',                  [ClientAuthController::class, 'updateProfile']);
        Route::post('/profile/avatar',          [ClientAuthController::class, 'uploadAvatar']);
        Route::delete('/profile/avatar',        [ClientAuthController::class, 'deleteAvatar']);
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=ClientAvatarTest`
Expected: PASS (8 tests).

- [ ] **Step 7: Run the full suite to check for regressions**

Run: `composer test`
Expected: PASS, no regressions in existing auth/profile tests.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Requests/Auth/UpdateAvatarRequest.php app/Http/Controllers/Api/ClientAuthController.php routes/api.php tests/Feature/ClientAvatarTest.php
git commit -m "feat: add profile avatar upload/delete endpoints"
```
