<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
    protected $appends = ['goal', 'percent', 'level', 'cashback_available_fcfa'];

    protected function casts(): array
    {
        return [
            'progress'              => 'array',
            'cashback_balance_fcfa' => 'decimal:2',
            'last_activity_at'      => 'datetime',
        ];
    }

    /**
     * Objectif du cycle actuel (Tampons/Achats) — `null` pour Cashback, qui
     * n'a pas de cycle. Avec des paliers multiples (`config['rewards']`), ce
     * n'est plus un seuil fixe : c'est la largeur du palier en cours, qui
     * dépend du nombre de cycles déjà complétés à vie (voir
     * `RewardTierService`).
     */
    public function getGoalAttribute(): ?int
    {
        $program = $this->loyaltyProgram;
        if (! $program || $program->type === 'cashback') {
            return null;
        }

        $service = app(\App\Services\Loyalty\RewardTierService::class);
        $tiers = $service->tiers($program);

        return $service->spanFor($tiers, $service->completedCycles($this));
    }

    /**
     * Solde cashback réellement utilisable : identique au solde brut
     * (`cashback_balance_fcfa`, colonne `decimal:2` — laissée telle quelle,
     * plusieurs tests s'appuient sur son format chaîne "1000.00") sauf si le
     * marchand a configuré une expiration (`config['cashback_expiry_days']`)
     * et qu'aucun cashback n'a été crédité depuis ce délai — auquel cas le
     * solde est considéré expiré et affiché/utilisable comme 0.
     *
     * Append distinct (`cashback_available_fcfa`) plutôt qu'un accesseur sur
     * `cashback_balance_fcfa` lui-même : ça aurait fait perdre le cast
     * `decimal:2` (un accesseur `get{X}Attribute` prend le pas sur le cast
     * pour le même nom d'attribut) partout où le solde brut doit rester
     * inchangé. Tout code voulant le solde utilisable doit lire cet append,
     * pas la colonne brute.
     *
     * Calculé à la lecture, comme le niveau et l'expiration des récompenses
     * (pas de tâche planifiée) : la colonne en base n'est jamais modifiée
     * par l'expiration, seule sa lecture est filtrée — l'historique des
     * crédits/débits réels reste donc exact. Simplification assumée :
     * l'expiration porte sur le solde entier depuis le dernier crédit, pas
     * un vieillissement FIFO crédit par crédit.
     */
    public function getCashbackAvailableFcfaAttribute(): float
    {
        $raw = (float) $this->cashback_balance_fcfa;
        $expiryDays = $this->loyaltyProgram?->config['cashback_expiry_days'] ?? null;
        if (! $expiryDays || $raw <= 0) {
            return $raw;
        }

        $lastEarn = DB::table('loyalty_transactions')
            ->where('loyalty_card_id', $this->id)
            ->where('type', 'cashback_earn')
            ->where('status', 'valid')
            ->max('created_at');

        if (! $lastEarn) {
            return $raw;
        }

        return Carbon::parse($lastEarn)->addDays((int) $expiryDays)->isPast() ? 0.0 : $raw;
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
