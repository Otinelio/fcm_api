<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
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
        'loyalty_transaction_id',
        'program_tier_id',
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
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    /** Longueur du `redeem_token` (QR unique scanné par le marchand). */
    private const REDEEM_TOKEN_LENGTH = 12;

    /** Tentatives de régénération avant d'abandonner sur collision. */
    private const REDEEM_TOKEN_RETRIES = 3;

    protected static function booted(): void
    {
        static::creating(function (LoyaltyReward $reward) {
            $reward->status ??= 'available';
            $reward->redeem_token ??= self::generateRedeemToken();
            $reward->unlocked_at ??= now();
        });
    }

    private static function generateRedeemToken(): string
    {
        return Str::upper(Str::random(self::REDEEM_TOKEN_LENGTH));
    }

    /**
     * Insertion avec retry atomique : pas de pré-vérification `exists()`
     * (TOCTOU entre le check et l'insert) — on tente l'insert et, si la clé
     * unique `redeem_token` rejette la ligne (collision), on régénère et
     * retente. À 62^12 combinaisons, une seconde collision est improbable
     * au point que 3 essais suffisent largement.
     *
     * {@inheritdoc}
     */
    protected function performInsert(Builder $query, array $options = [])
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return parent::performInsert($query, $options);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= self::REDEEM_TOKEN_RETRIES - 1) {
                    throw $exception;
                }

                $this->redeem_token = self::generateRedeemToken();
            }
        }
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

    public function programTier()
    {
        return $this->belongsTo(LoyaltyProgramTier::class);
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}
