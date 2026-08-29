<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un parrainage : client B a rejoint l'établissement via le QR de
 * parrainage du client A. Reste `pending` jusqu'à la première opération de
 * fidélité réelle de B sur cet établissement (voir `ReferralService`), qui
 * seule fait passer le parrainage à `validated` et débloque la récompense
 * de A. Ni le scan du QR, ni la création du compte de B ne créent une
 * récompense — seule cette transition le fait.
 */
class Referral extends Model
{
    protected $fillable = [
        'restaurant_id',
        'referrer_client_id',
        'referrer_card_id',
        'referred_client_id',
        'referred_card_id',
        'status',
        'validated_at',
        'reward_loyalty_reward_id',
    ];

    protected function casts(): array
    {
        return [
            'validated_at' => 'datetime',
        ];
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function referrerClient()
    {
        return $this->belongsTo(Client::class, 'referrer_client_id');
    }

    public function referredClient()
    {
        return $this->belongsTo(Client::class, 'referred_client_id');
    }

    public function referrerCard()
    {
        return $this->belongsTo(LoyaltyCard::class, 'referrer_card_id');
    }

    public function referredCard()
    {
        return $this->belongsTo(LoyaltyCard::class, 'referred_card_id');
    }

    public function reward()
    {
        return $this->belongsTo(LoyaltyReward::class, 'reward_loyalty_reward_id');
    }
}
