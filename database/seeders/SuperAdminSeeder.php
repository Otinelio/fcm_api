<?php

namespace Database\Seeders;

use App\Models\SuperAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SuperAdmin::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'role' => 'super_admin',
            ]
        );

        SuperAdmin::updateOrCreate(
            ['email' => 'otidumeando@gmail.com'],
            [
                'name' => 'Othnelio',
                'password' => Hash::make('Othnelio@0812'),
                'role' => 'super_admin',
            ]
        );
    }
}
