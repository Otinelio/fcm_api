<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use Illuminate\Support\Facades\DB;

/**
 * Résout les paliers d'un programme (objectif + niveau + récompense
 * unifiés). Remplace `RewardTierService` et `LoyaltyLevelService`.
 *
 * Distinction volontaire, cœur de la conception (voir spec) :
 * - Tampons/Achats, 1 seul palier configuré : comportement "cycle répété"
 *   existant, `progress['stamps_current']` remis à zéro à chaque déblocage,
 *   jamais de niveau affiché. Géré directement par
 *   `MerchantDashboardController`, pas par ce service (`resolve()` renvoie
 *   `tiers: []`, `level_name: null`).
 * - Tampons/Achats à 2 paliers ou plus, OU Cashback à 1 palier ou plus :
 *   cumulatif à vie par défaut (jamais reset), plafonné au dernier palier
 *   une fois atteint. C'est ce que `resolve()` calcule. Le cashback n'a
 *   jamais de comportement "cycle répété", même à 1 seul palier configuré —
 *   un palier cashback attribue un niveau définitif, pas un objectif
 *   cyclique (voir `lifetimeMetric()` pour la bascule cumulé/solde).
 *
 * Icône/nom de niveau : pour les paliers en position 1 à 5, nom et icône
 * sont imposés côté client (`LoyaltyLevel.forPosition`, ordre Bronze <
 * Argent < Or < Platine < Fidèle) — ce service ne les calcule pas, il
 * expose seulement la `position` (rang 1-based, déterministe). Au-delà de
 * la position 5, le marchand choisit nom (`level_name`, texte libre déjà
 * existant) et icône (`icon_key`, palette côté client) — ce service se
 * contente de faire transiter `icon_key` tel que stocké.
 */
class LoyaltyTierService
{
    /**
     * Clé canonique d'un niveau de fidélité, dérivée de son nom tel que
     * configuré par le marchand (`level_name` des paliers). Permet au client
     * mobile de filtrer/représenter les niveaux sans faire de matching
     * fragile sur les libellés libres (« Or », « Gold », « VIP Or »...).
     * Matching insensible à la casse et aux accents ; `custom` en fallback.
     */
    public function levelKey(?string $levelName): string
    {
        $n = mb_strtolower(trim((string) $levelName));
        $n = strtr($n, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e',
            'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o',
            'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);

        return match (true) {
            str_contains($n, 'bronze') => 'bronze',
            str_contains($n, 'argent'), str_contains($n, 'silver') => 'silver',
            str_contains($n, 'platine'), str_contains($n, 'platinum') => 'platinum',
            str_contains($n, 'gold') => 'gold',
            $n === 'or' || str_ends_with($n, ' or') || str_starts_with($n, 'or ') => 'gold',
            default => 'custom',
        };
    }

    /**
     * Vide `reward_description` quand ce palier précis n'est pas encore
     * débloqué et que le marchand le masque (`tier.reveal_reward === false`,
     * réglage propre à chaque palier — un programme peut cacher un seul
     * palier "surprise" et laisser les autres visibles).
     */
    private function redact(array $tier): array
    {
        return ($tier['reveal_reward'] ?? true) ? $tier : [...$tier, 'reward_description' => ''];
    }

    /**
     * @return array<int, array{id: ?int, order: int, position: int, goal: int, level_name: ?string, icon_key: ?string, reward_description: string, reveal_reward: bool, validity_days: ?int}>
     *                                                                                                                                                                                                    Trié par `goal` croissant.
     */
    public function tiers(?LoyaltyProgram $program): array
    {
        if ($program === null) {
            return [];
        }

        $rows = $program->tiers;
        if ($rows->isNotEmpty()) {
            return $rows
                ->sortBy('goal')
                ->values()
                ->map(fn ($r, $i) => [
                    'id' => $r->id,
                    'order' => $r->order,
                    'position' => $i + 1,
                    'goal' => max(1, (int) $r->goal),
                    'level_name' => $r->level_name,
                    'icon_key' => $r->icon_key,
                    'reward_description' => $r->reward_description,
                    'reveal_reward' => $r->reveal_reward,
                    'validity_days' => $r->validity_days ?? ($program->config['reward_validity_days'] ?? null),
                ])
                ->all();
        }

        // Programme jamais migré vers la table de paliers (tests qui
        // construisent `LoyaltyProgram` directement, ou programme historique
        // non passé par la commande de migration) — reproduit exactement le
        // fallback mono-palier de l'ancien `RewardTierService`. Le cashback
        // n'a par défaut aucun palier (comportement actuel : pas de cycle).
        if ($program->type === 'cashback') {
            return [];
        }

        $goal = (int) ($program->config['goal'] ?? 10);
        $title = (string) ($program->config['reward_description'] ?? '') ?: 'Récompense débloquée';

        return [[
            'id' => null,
            'order' => 1,
            'position' => 1,
            'goal' => max(1, $goal),
            'level_name' => null,
            'icon_key' => null,
            'reward_description' => $title,
            'reveal_reward' => true,
            'validity_days' => $program->config['reward_validity_days'] ?? null,
        ]];
    }

    /**
     * Palier vers lequel la carte progresse actuellement (pas encore
     * atteint) — sert d'aperçu tant qu'aucune `LoyaltyReward` n'est encore
     * débloquée (voir `LoyaltyCard::getNextRewardAttribute`). Contrairement à
     * `resolve()['tiers']`, jamais vide pour un mono-palier : c'est
     * justement le seul cas où ce champ a un rôle (pas de roadmap de niveau
     * pour montrer la récompense visée).
     *
     * @return array{id: ?int, order: int, position: int, goal: int, level_name: ?string, icon_key: ?string, reward_description: string, reveal_reward: bool, validity_days: ?int}|null
     */
    public function nextReward(LoyaltyCard $card): ?array
    {
        $tiers = $this->tiers($card->loyaltyProgram);
        if ($tiers === []) {
            return null;
        }

        // Mono-palier "cycle répété" (Tampons/Achats uniquement) : toujours
        // le même palier visé, jamais atteint durablement (le cycle remet la
        // métrique à zéro). Le cashback n'a pas de cycle — même à 1 seul
        // palier configuré, il rejoint la logique multi-palier ci-dessous
        // (métrique jamais reset, palier réellement débloqué une fois pour
        // toutes une fois atteint, voir `resolve()`).
        if (count($tiers) === 1 && $card->loyaltyProgram?->type !== 'cashback') {
            return $this->redact($tiers[0]);
        }

        $metric = $this->lifetimeMetric($card);

        foreach ($tiers as $tier) {
            if ($tier['goal'] > $metric) {
                return $this->redact($tier);
            }
        }

        // Tous les paliers sont atteints : aperçu du dernier (le max), déjà
        // débloqué en réalité — jamais masqué, quel que soit le réglage.
        return $tiers[count($tiers) - 1];
    }

    public function lifetimeCashback(LoyaltyCard $card): float
    {
        return (float) DB::table('loyalty_transactions')
            ->where('loyalty_card_id', $card->id)
            ->where('type', 'cashback_earn')
            ->where('status', 'valid')
            ->sum('value');
    }

    /**
     * Métrique de progression des paliers : jamais reset. Tampons/Achats =
     * `progress['stamps_current']`, qui n'est plus remis à zéro dès qu'un
     * programme a 2+ paliers (voir
     * `MerchantDashboardController::grantStampOrPoints`).
     *
     * Cashback : selon `config['cashback_tier_basis']` (réglage unique pour
     * tout le programme — les paliers partagent une roadmap avec UNE seule
     * métrique triable/croissante, on ne peut pas en mélanger deux) :
     * - `cumulative` (défaut) : cashback cumulé généré à vie, ne redescend
     *   jamais — utiliser le solde ne fait jamais perdre un niveau.
     * - `balance` : solde disponible en direct (`cashback_available_fcfa`),
     *   peut redescendre si le client utilise son cashback — le niveau
     *   affiché suit alors le solde. Les récompenses déjà débloquées à un
     *   palier restent acquises même si le niveau redescend ensuite (voir
     *   `MerchantDashboardController::cashbackTiersToUnlock`, qui ne
     *   re-débloque jamais un palier déjà servi).
     */
    public function lifetimeMetric(LoyaltyCard $card): float
    {
        if ($card->loyaltyProgram?->type !== 'cashback') {
            return (float) ($card->progress['stamps_current'] ?? 0);
        }

        $basis = $card->loyaltyProgram->config['cashback_tier_basis'] ?? 'cumulative';

        return $basis === 'balance'
            ? (float) $card->cashback_available_fcfa
            : $this->lifetimeCashback($card);
    }

    /** @return array{level_name: ?string, percent_to_next: ?int, is_max_level: bool, position: ?int, icon_key: ?string, tiers: array} */
    public function resolve(LoyaltyCard $card): array
    {
        $tiers = $this->tiers($card->loyaltyProgram);
        $isCashback = $card->loyaltyProgram?->type === 'cashback';

        // Aucun palier configuré, ou mono-palier "cycle répété" (Tampons/
        // Achats seulement — voir `nextReward()`) : pas de niveau à afficher.
        // Le cashback à 1 seul palier configuré, lui, rejoint le calcul
        // ci-dessous comme s'il en avait plusieurs (spec : même un palier
        // unique attribue un niveau, jamais de cycle/reset pour le cashback).
        if ($tiers === [] || (count($tiers) === 1 && ! $isCashback)) {
            return ['level_name' => null, 'percent_to_next' => null, 'is_max_level' => false, 'position' => null, 'icon_key' => null, 'tiers' => []];
        }

        $metric = $this->lifetimeMetric($card);

        $current = null;
        $next = null;
        foreach ($tiers as $tier) {
            if ($tier['goal'] <= $metric) {
                $current = $tier;
            } else {
                $next = $tier;
                break;
            }
        }

        // Le statut de chaque palier reflète UNIQUEMENT le cycle en cours
        // (`$metric`, déjà remis à zéro par `grantStampOrPoints` quand
        // `loops=true`) : un palier franchi au cycle 1 redevient "à venir"
        // dès le début du cycle 2, jusqu'à être re-franchi. La récompense
        // déjà accordée au cycle précédent reste elle bien acquise (une
        // `LoyaltyReward` distincte existe déjà, jamais touchée par un reset
        // de cycle — voir `removeStamp`/`redeemReward`) ; seul l'indicateur
        // de progression du palier dans la roadmap se réaligne sur le cycle
        // courant, pour que le marchand/client voie où en est CE cycle-ci.
        $tiersWithStatus = collect($tiers)->values()->map(function ($tier) use ($metric, $next) {
            $status = $tier['goal'] <= $metric
                ? 'reached'
                : ($next !== null && $tier['order'] === $next['order'] ? 'current' : 'upcoming');

            $tier = $status === 'reached' ? $tier : $this->redact($tier);

            return [...$tier, 'status' => $status];
        })->all();

        if ($current === null) {
            $firstGoal = $tiers[0]['goal'];

            return [
                'level_name' => null,
                'percent_to_next' => (int) round(max(0, min(100, ($metric / $firstGoal) * 100))),
                'is_max_level' => false,
                'position' => null,
                'icon_key' => null,
                'tiers' => $tiersWithStatus,
            ];
        }

        if ($next === null) {
            return [
                'level_name' => $current['level_name'],
                'percent_to_next' => null,
                'is_max_level' => true,
                'position' => $current['position'],
                'icon_key' => $current['icon_key'],
                'tiers' => $tiersWithStatus,
            ];
        }

        $span = $next['goal'] - $current['goal'];
        $percent = $span > 0 ? (($metric - $current['goal']) / $span) * 100 : 0;

        return [
            'level_name' => $current['level_name'],
            'percent_to_next' => (int) round(max(0, min(100, $percent))),
            'is_max_level' => false,
            'position' => $current['position'],
            'icon_key' => $current['icon_key'],
            'tiers' => $tiersWithStatus,
        ];
    }
}
