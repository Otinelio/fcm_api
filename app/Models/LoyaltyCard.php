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

    /**
     * Champs calculés, exposés dans toute sérialisation de la carte (fetch
     * initial ET diffusion Reverb — voir `LoyaltyCardUpdated::broadcastWith`,
     * qui lit ces mêmes accesseurs pour garantir un payload identique aux
     * deux endroits, ce qui corrige le bug où `goal` ne se rafraîchissait
     * qu'au fetch initial, jamais en temps réel).
     */
    protected $appends = ['goal', 'percent', 'level'];

    protected function casts(): array
    {
        return [
            'progress'              => 'array',
            'cashback_balance_fcfa' => 'decimal:2',
            'last_activity_at'      => 'datetime',
        ];
    }

    /** Objectif du cycle actuel (Tampons/Achats) — `null` pour Cashback, qui n'a pas de cycle. */
    public function getGoalAttribute(): ?int
    {
        $program = $this->loyaltyProgram;
        if (! $program || $program->type === 'cashback') {
            return null;
        }

        return (int) ($program->config['goal'] ?? 10);
    }

    /**
     * Pourcentage affiché au client, calculé côté serveur :
     * - Tampons/Achats : progression dans le cycle actuel (`current / goal`).
     * - Cashback : pas de cycle, donc pourcentage vers le niveau de
     *   fidélité suivant (jamais le montant brut dépensé/gagné à vie).
     */
    public function getPercentAttribute(): int
    {
        $program = $this->loyaltyProgram;
        if (! $program) {
            return 0;
        }

        if ($program->type === 'cashback') {
            return $this->level['percent_to_next'];
        }

        $goal = $this->goal;
        if (! $goal || $goal <= 0) {
            return 0;
        }

        $current = (int) ($this->progress['stamps_current'] ?? 0);

        return (int) round(max(0, min(100, ($current / $goal) * 100)));
    }

    /** Niveau de fidélité (indépendant des cycles/récompenses) — voir `LoyaltyLevelService`. */
    public function getLevelAttribute(): array
    {
        return app(\App\Services\Loyalty\LoyaltyLevelService::class)->levelFor($this);
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
