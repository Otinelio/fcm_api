<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Utilisateur de test principal avec un solde de points connu
        User::factory()->create([
            'name'           => 'Test User',
            'email'          => 'test@example.com',
            'loyalty_points' => 350,
        ]);

        // Quelques utilisateurs aléatoires pour peupler la base
        User::factory(3)->create();
    }
}
