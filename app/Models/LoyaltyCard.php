<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LoyaltyCard extends Model
{
    protected $fillable = [
        'client_id',
        'restaurant_id',
        'loyalty_program_id',
        'card_code',
        'qr_token',
        'progress',
        'cashback_balance_fcfa',
        'vip_tier',
        'status',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'progress'              => 'array',
            'cashback_balance_fcfa' => 'decimal:2',
            'last_activity_at'      => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LoyaltyCard $card) {
            $card->card_code ??= self::generateCardCode();
            $card->qr_token ??= (string) Str::uuid();
            $card->status ??= 'active';
            $card->progress ??= ['stamps_current' => 0];
        });
    }

    private static function generateCardCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (self::where('card_code', $code)->exists());

        return $code;
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function loyaltyProgram()
    {
        return $this->belongsTo(LoyaltyProgram::class);
    }
}
