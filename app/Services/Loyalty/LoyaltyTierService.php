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
 * - 1 seul palier configuré : comportement "cycle répété" existant,
 *   `progress['stamps_current']` remis à zéro à chaque déblocage, jamais de
 *   niveau affiché. Géré directement par `MerchantDashboardController`, pas
 *   par ce service (`resolve()` renvoie `tiers: []`, `level_name: null`).
 * - 2 paliers ou plus : cumulatif à vie (jamais reset), plafonné au dernier
 *   palier une fois atteint. C'est ce que `resolve()` calcule.
 */
class LoyaltyTierService
{
    private const ICONS = ['🥉', '🥈', '🥇', '💎', '👑'];
    private const DEFAULT_ICON = '⭐';

    public function iconForRank(int $rank): string
    {
        return self::ICONS[$rank - 1] ?? self::DEFAULT_ICON;
    }

    /**
     * @return array<int, array{id: ?int, order: int, goal: int, level_name: ?string, reward_description: string, validity_days: ?int}>
     * Trié par `goal` croissant.
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
                ->map(fn ($r) => [
                    'id'                  => $r->id,
                    'order'               => $r->order,
                    'goal'                => max(1, (int) $r->goal),
                    'level_name'          => $r->level_name,
                    'reward_description'  => $r->reward_description,
                    'validity_days'       => $r->validity_days ?? ($program->config['reward_validity_days'] ?? null),
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
            'id'                 => null,
            'order'              => 1,
            'goal'               => max(1, $goal),
            'level_name'         => null,
            'reward_description' => $title,
            'validity_days'      => $program->config['reward_validity_days'] ?? null,
        ]];
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
     * Métrique multi-palier : jamais reset. Cashback = cashback cumulé à
     * vie. Tampons/Achats = `progress['stamps_current']`, qui n'est plus
     * remis à zéro dès qu'un programme a 2+ paliers (voir
     * `MerchantDashboardController::grantStampOrPoints`).
     */
    private function lifetimeMetric(LoyaltyCard $card): float
    {
        return $card->loyaltyProgram?->type === 'cashback'
            ? $this->lifetimeCashback($card)
            : (float) ($card->progress['stamps_current'] ?? 0);
    }

    /** @return array{level_name: ?string, percent_to_next: ?int, is_max_level: bool, tiers: array} */
    public function resolve(LoyaltyCard $card): array
    {
        $tiers = $this->tiers($card->loyaltyProgram);

        if (count($tiers) <= 1) {
            return ['level_name' => null, 'percent_to_next' => null, 'is_max_level' => false, 'tiers' => []];
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

        $tiersWithStatus = collect($tiers)->values()->map(function ($tier, $i) use ($metric, $next) {
            $status = $tier['goal'] <= $metric
                ? 'reached'
                : ($next !== null && $tier['order'] === $next['order'] ? 'current' : 'upcoming');

            return [...$tier, 'icon' => $this->iconForRank($i + 1), 'status' => $status];
        })->all();

        if ($current === null) {
            $firstGoal = $tiers[0]['goal'];

            return [
                'level_name'      => null,
                'percent_to_next' => (int) round(max(0, min(100, ($metric / $firstGoal) * 100))),
                'is_max_level'    => false,
                'tiers'           => $tiersWithStatus,
            ];
        }

        if ($next === null) {
            return [
                'level_name'      => $current['level_name'],
                'percent_to_next' => null,
                'is_max_level'    => true,
                'tiers'           => $tiersWithStatus,
            ];
        }

        $span = $next['goal'] - $current['goal'];
        $percent = $span > 0 ? (($metric - $current['goal']) / $span) * 100 : 0;

        return [
            'level_name'      => $current['level_name'],
            'percent_to_next' => (int) round(max(0, min(100, $percent))),
            'is_max_level'    => false,
            'tiers'           => $tiersWithStatus,
        ];
    }
}
