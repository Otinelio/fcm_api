<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Formules d'abonnement marchand.
 *
 * L'écran « Abonnement » du dashboard propose ces trois `slug` ; sans eux en
 * base, la validation `exists:plans,slug` de `PUT /auth/merchant/plan`
 * rejetait tout changement de formule.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug'                      => 'free',
                'name'                      => 'Démarrage',
                'price_monthly'             => 0,
                'price_yearly'              => 0,
                'max_staff'                 => 1,
                'max_loyalty_programs'      => 1,
                'max_clients'               => 50,
                'allows_cashback'           => false,
                'allows_vip'                => false,
                'allows_auto_notifications' => false,
                'allows_geolocation'        => false,
                'allows_marketplace'        => false,
            ],
            [
                'slug'                      => 'pro',
                'name'                      => 'Pro',
                'price_monthly'             => 9900,
                'price_yearly'              => 99000,
                'max_staff'                 => 5,
                'max_loyalty_programs'      => 3,
                'max_clients'               => 500,
                'allows_cashback'           => true,
                'allows_vip'                => true,
                'allows_auto_notifications' => true,
                'allows_geolocation'        => true,
                'allows_marketplace'        => false,
            ],
            [
                'slug'                      => 'business',
                'name'                      => 'Business',
                'price_monthly'             => 24900,
                'price_yearly'              => 249000,
                'max_staff'                 => 25,
                'max_loyalty_programs'      => 10,
                // `null` = illimité, comme annoncé sur la fiche du plan.
                'max_clients'               => null,
                'allows_cashback'           => true,
                'allows_vip'                => true,
                'allows_auto_notifications' => true,
                'allows_geolocation'        => true,
                'allows_marketplace'        => true,
            ],
        ];

        foreach ($plans as $plan) {
            DB::table('plans')->updateOrInsert(
                ['slug' => $plan['slug']],
                $plan + [
                    'is_active'  => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }
}
