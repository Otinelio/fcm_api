<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyCard extends Model
{
    protected $fillable = ['user_id', 'stamps', 'required_stamps', 'reward_unlocked_at'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
