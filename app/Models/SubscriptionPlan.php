<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $table = 'plans';

    protected $fillable = [
        'name',
        'slug',
        'price_monthly',
        'price_yearly',
        'max_staff',
        'max_loyalty_programs',
        'max_clients',
        'allows_cashback',
        'allows_vip',
        'allows_auto_notifications',
        'allows_geolocation',
        'allows_marketplace',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'allows_cashback' => 'boolean',
            'allows_vip' => 'boolean',
            'allows_auto_notifications' => 'boolean',
            'allows_geolocation' => 'boolean',
            'allows_marketplace' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
