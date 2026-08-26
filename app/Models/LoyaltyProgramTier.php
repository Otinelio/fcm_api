<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyProgramTier extends Model
{
    protected $fillable = [
        'loyalty_program_id',
        'order',
        'goal',
        'level_name',
        'icon_key',
        'reward_description',
        'reveal_reward',
        'validity_days',
    ];

    protected $casts = [
        'reveal_reward' => 'boolean',
    ];

    public function loyaltyProgram()
    {
        return $this->belongsTo(LoyaltyProgram::class);
    }
}
