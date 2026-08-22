<?php

namespace Tests\Unit\Support;

use App\Models\Restaurant;
use App\Models\StaffUser;
use App\Support\CurrentActor;
use App\Support\StaffUserInactiveException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CurrentActorTest extends TestCase
{
    use RefreshDatabase;

    private function restaurant(): Restaurant
    {
        return Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('secret123'),
        ]);
    }

    public function test_plain_restaurant_token_resolves_to_admin(): void
    {
        $restaurant = $this->restaurant();
        $token = $restaurant->createToken('merchant-app')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/merchant/me');

        $response->assertOk();
        // La résolution elle-même est testée via un accès direct au modèle.
        $request = Request::create('/');
        $request->setUserResolver(fn () => $restaurant);
        $sanctumToken = $restaurant->tokens()->first();
        $restaurant->withAccessToken($sanctumToken);
        $request->setUserResolver(fn () => $restaurant);

        $actor = CurrentActor::resolve($request);
        $this->assertSame('restaurant', $actor->type);
        $this->assertSame('admin', $actor->role);
        $this->assertTrue($actor->isAdmin());
        $this->assertNull($actor->staffUser);
    }

    public function test_staff_ability_token_resolves_to_that_staff_user(): void
    {
        $restaurant = $this->restaurant();
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean@example.com', 'password' => 'secret', 'role' => 'operator',
        ]);
        $token = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->accessToken;

        $request = Request::create('/');
        $restaurant->withAccessToken($token);
        $request->setUserResolver(fn () => $restaurant);

        $actor = CurrentActor::resolve($request);
        $this->assertSame('staff', $actor->type);
        $this->assertSame('operator', $actor->role);
        $this->assertFalse($actor->isAdmin());
        $this->assertTrue($actor->staffUser->is($staff));
    }

    public function test_staff_admin_role_is_admin(): void
    {
        $restaurant = $this->restaurant();
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Awa',
            'email' => 'awa@example.com', 'password' => 'secret', 'role' => 'admin',
        ]);
        $token = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->accessToken;

        $request = Request::create('/');
        $restaurant->withAccessToken($token);
        $request->setUserResolver(fn () => $restaurant);

        $this->assertTrue(CurrentActor::resolve($request)->isAdmin());
    }

    public function test_inactive_staff_user_throws(): void
    {
        $restaurant = $this->restaurant();
        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id, 'name' => 'Jean',
            'email' => 'jean2@example.com', 'password' => 'secret',
            'role' => 'operator', 'is_active' => false,
        ]);
        $token = $restaurant->createToken("staff:{$staff->id}", ["staff:{$staff->id}"])->accessToken;

        $request = Request::create('/');
        $restaurant->withAccessToken($token);
        $request->setUserResolver(fn () => $restaurant);

        $this->expectException(StaffUserInactiveException::class);
        CurrentActor::resolve($request);
    }

    public function test_no_authenticated_user_defaults_to_admin(): void
    {
        $request = Request::create('/');
        $actor = CurrentActor::resolve($request);

        $this->assertSame('restaurant', $actor->type);
        $this->assertSame('admin', $actor->role);
    }
}
