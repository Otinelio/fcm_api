<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Une récompense débloquée (cycle Tampons/Achats atteint), traçée du
 * déblocage à l'utilisation. Statuts stockés : `available`/`used`/`canceled`.
 * L'expiration n'est PAS un statut stocké — elle se calcule à la lecture
 * depuis `expires_at` (voir `isExpiredAttribute`), même principe que le
 * niveau de fidélité (`LoyaltyLevelService`) : pas de tâche planifiée.
 */
class LoyaltyReward extends Model
{
    protected $fillable = [
        'loyalty_card_id',
        'restaurant_id',
        'title',
        'status',
        'redeem_token',
        'unlocked_at',
        'expires_at',
        'used_at',
        'used_by_staff_user_id',
        'canceled_at',
        'canceled_by_staff_user_id',
        'cancel_reason',
    ];

    protected $appends = ['is_expired'];

    protected function casts(): array
    {
        return [
            'unlocked_at' => 'datetime',
            'expires_at'  => 'datetime',
            'used_at'     => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LoyaltyReward $reward) {
            $reward->status ??= 'available';
            $reward->redeem_token ??= (string) Str::uuid();
            $reward->unlocked_at ??= now();
        });
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->status === 'available'
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    /** Utilisable maintenant : disponible, non expirée. */
    public function isRedeemable(): bool
    {
        return $this->status === 'available' && ! $this->is_expired;
    }

    public function loyaltyCard()
    {
        return $this->belongsTo(LoyaltyCard::class);
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}
