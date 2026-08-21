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
        'reward_description',
        'validity_days',
    ];

    public function loyaltyProgram()
    {
        return $this->belongsTo(LoyaltyProgram::class);
    }
}
