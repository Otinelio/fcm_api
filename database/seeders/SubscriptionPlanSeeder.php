<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        SubscriptionPlan::insert([
            ['name' => 'Starter', 'slug' => 'starter', 'price_xof' => 5000, 'duration_days' => 30, 'features' => json_encode(['1 restaurant', 'Carte fidélité de base']), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Pro', 'slug' => 'pro', 'price_xof' => 15000, 'duration_days' => 30, 'features' => json_encode(['Notifications push', 'Statistiques avancées']), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Premium', 'slug' => 'premium', 'price_xof' => 150000, 'duration_days' => 365, 'features' => json_encode(['Tout Pro', 'Support prioritaire', '-15% vs mensuel']), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
