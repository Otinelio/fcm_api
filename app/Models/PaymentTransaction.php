<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'restaurant_subscription_id', 'fedapay_transaction_id', 'fedapay_reference',
        'amount_xof', 'mode', 'status', 'raw_payload',
    ];

    protected $casts = ['raw_payload' => 'array'];

    public function subscription()
    {
        return $this->belongsTo(RestaurantSubscription::class, 'restaurant_subscription_id');
    }
}
