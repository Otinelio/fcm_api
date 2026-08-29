<?php

namespace App\Models;

use App\Services\Loyalty\LoyaltyTierService;
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
        'referral_code',
        'referral_qr_token',
        'progress',
        'cashback_balance_fcfa',
        'vip_tier',
        'status',
        'last_activity_at',
        'completed_at',
        'cycles_completed',
        'max_level_name',
        'max_level_order',
        'max_level_reached_at',
    ];

    /**
     * Champs calculés, exposés dans toute sérialisation de la carte (fetch
     * initial ET diffusion Reverb — voir `LoyaltyCardUpdated::broadcastWith`,
     * qui lit ces mêmes accesseurs pour garantir un payload identique aux
     * deux endroits, ce qui corrige le bug où `goal` ne se rafraîchissait
     * qu'au fetch initial, jamais en temps réel).
     */
    protected $appends = ['goal', 'percent', 'level', 'tiers', 'cashback_available_fcfa', 'next_reward'];

    protected function casts(): array
    {
        return [
            'progress'              => 'array',
            'cashback_balance_fcfa' => 'decimal:2',
            'last_activity_at'      => 'datetime',
            'completed_at'          => 'datetime',
            'max_level_reached_at'  => 'datetime',
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

        $service = app(LoyaltyTierService::class);
        $tiers = $service->tiers($program);

        if (count($tiers) <= 1) {
            // Mono-palier : comportement cycle répété inchangé — objectif
            // constant, span du palier unique.
            return $tiers[0]['goal'] ?? 10;
        }

        // Multi-palier : objectif = seuil ABSOLU du prochain palier non
        // atteint (la métrique — `stamps_current` — est elle-même un cumul à
        // vie, pas un compteur par cycle : renvoyer un écart produirait un
        // affichage incohérent, ex. "700/500" pour une carte à 700 cumulés
        // progressant vers un palier à 1000).
        $current = (float) ($this->progress['stamps_current'] ?? 0);
        foreach ($tiers as $tier) {
            if ($tier['goal'] > $current) {
                return $tier['goal'];
            }
        }

        return null; // niveau max atteint, pas de palier suivant.
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

        $service = app(LoyaltyTierService::class);
        $tiers = $service->tiers($program);

        if ($program->type === 'cashback') {
            return $this->level['percent_to_next'] ?? 0;
        }

        if (count($tiers) > 1) {
            return $this->level['percent_to_next'] ?? 100;
        }

        $goal = $this->goal;
        if (! $goal || $goal <= 0) {
            return 0;
        }

        $current = (int) ($this->progress['stamps_current'] ?? 0);

        return (int) round(max(0, min(100, ($current / $goal) * 100)));
    }

    /** Niveau de fidélité — `null` tant que le programme n'a qu'un seul palier configuré (voir `LoyaltyTierService`). */
    public function getLevelAttribute(): ?array
    {
        $resolved = app(LoyaltyTierService::class)->resolve($this);

        if ($resolved['level_name'] === null && $resolved['tiers'] === []) {
            return null;
        }

        // Programme multi-palier mais aucun palier encore atteint (client
        // tout juste ajouté, 0 tampon/point/cashback à vie) : `position` et
        // `level_name` sont `null` ici (voir `LoyaltyTierService::resolve`).
        // `levelKey(null)` retomberait sur son fallback `'custom'` (« Fidèle »,
        // le DERNIER palier) — un client qui débute doit afficher le premier
        // palier (Bronze), pas le dernier.
        if ($resolved['position'] === null) {
            return [
                'name'            => null,
                'key'             => 'bronze',
                'percent_to_next' => $resolved['percent_to_next'],
                'is_max_level'    => false,
                'position'        => 1,
                'icon_key'        => null,
            ];
        }

        $tierService = app(LoyaltyTierService::class);

        return [
            'name'            => $resolved['level_name'],
            'key'             => $tierService->levelKey($resolved['level_name']),
            'percent_to_next' => $resolved['percent_to_next'],
            'is_max_level'    => $resolved['is_max_level'],
            'position'        => $resolved['position'],
            'icon_key'        => $resolved['icon_key'],
        ];
    }

    /** Roadmap des paliers (vide si un seul palier configuré) — pour la vue "progression" côté client. */
    public function getTiersAttribute(): array
    {
        return app(LoyaltyTierService::class)->resolve($this)['tiers'];
    }

    /**
     * Palier (objectif + récompense réelle) vers lequel la carte progresse,
     * pas encore débloqué — permet à l'écran carte d'afficher la vraie
     * récompense visée (au lieu d'un texte générique) tant qu'aucune
     * `LoyaltyReward` n'existe encore, y compris pour un mono-palier (qui
     * n'a pas de roadmap de niveau, voir `tiers`).
     */
    public function getNextRewardAttribute(): ?array
    {
        return app(LoyaltyTierService::class)->nextReward($this);
    }

    protected static function booted(): void
    {
        static::creating(function (LoyaltyCard $card) {
            $card->card_code ??= self::generateUniqueCode('card_code');
            $card->qr_token ??= (string) Str::uuid();
            $card->referral_code ??= self::generateUniqueCode('referral_code');
            $card->referral_qr_token ??= (string) Str::uuid();
            $card->status ??= 'active';
            $card->progress ??= ['stamps_current' => 0];
        });
    }

    private static function generateUniqueCode(string $column): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (self::where($column, $code)->exists());

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
