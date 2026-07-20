<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = ['name', 'slug', 'price_xof', 'duration_days', 'features', 'is_active'];

    protected $casts = ['features' => 'array', 'is_active' => 'boolean'];
}
