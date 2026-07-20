<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantSubscription extends Model
{
    protected $fillable = ['restaurant_id', 'subscription_plan_id', 'status', 'starts_at', 'ends_at', 'auto_renew'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'auto_renew' => 'boolean'];

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function transactions()
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->ends_at?->isFuture();
    }
}
