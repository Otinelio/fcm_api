<?php

namespace Tests\Unit\Models;

use App\Models\Restaurant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_is_hashed_automatically(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce@example.com', 'password' => bcrypt('secret123'),
        ]);

        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Jean',
            'email'         => 'jean@example.com',
            'password'      => 'plainpassword',
            'role'          => 'operator',
        ]);

        $this->assertTrue(Hash::check('plainpassword', $staff->password));
    }

    public function test_is_active_defaults_to_true(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce2@example.com', 'password' => bcrypt('secret123'),
        ]);

        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Jean',
            'email'         => 'jean2@example.com',
            'password'      => 'plainpassword',
            'role'          => 'operator',
        ]);

        $this->assertTrue($staff->fresh()->is_active);
    }

    public function test_role_must_be_admin_or_operator(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce3@example.com', 'password' => bcrypt('secret123'),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        StaffUser::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Jean',
            'email'         => 'jean3@example.com',
            'password'      => 'plainpassword',
            'role'          => 'manager',
        ]);
    }

    public function test_belongs_to_restaurant(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Chez Awa', 'category' => 'Restaurant',
            'email' => 'commerce4@example.com', 'password' => bcrypt('secret123'),
        ]);

        $staff = StaffUser::create([
            'restaurant_id' => $restaurant->id,
            'name'          => 'Jean',
            'email'         => 'jean4@example.com',
            'password'      => 'plainpassword',
            'role'          => 'operator',
        ]);

        $this->assertTrue($staff->restaurant->is($restaurant));
    }
}
