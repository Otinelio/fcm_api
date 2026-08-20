<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyCard;
use Illuminate\Support\Facades\DB;

/**
 * Niveau de fidélité (Bronze/Argent/Or...) : indépendant des cycles de
 * récompense (Tampons/Achats) ou du solde (Cashback), calculé à la lecture
 * depuis un historique jamais affecté par un reset de cycle.
 *
 * Métrique à vie :
 * - Tampons/Achats : nombre de cycles complétés (`loyalty_transactions`
 *   type `cycle_completed`).
 * - Cashback : somme du cashback jamais crédité (type `cashback_earn`) —
 *   ce nombre brut n'est jamais renvoyé tel quel, seuls le niveau et le
 *   pourcentage vers le suivant en sortent.
 */
class LoyaltyLevelService
{
    private const DEFAULT_LEVELS = [
        ['name' => 'Membre', 'threshold' => 0],
    ];

    public function levelFor(LoyaltyCard $card): array
    {
        $program = $card->loyaltyProgram;

        $levels = $program?->config['levels'] ?? null;
        if (! is_array($levels) || count($levels) === 0) {
            $levels = self::DEFAULT_LEVELS;
        }

        $metric = $program?->type === 'cashback'
            ? $this->lifetimeCashback($card)
            : $this->completedCycles($card);

        return $this->resolve($metric, $levels);
    }

    private function completedCycles(LoyaltyCard $card): int
    {
        return (int) DB::table('loyalty_transactions')
            ->where('loyalty_card_id', $card->id)
            ->where('type', 'cycle_completed')
            ->where('status', 'valid')
            ->count();
    }

    private function lifetimeCashback(LoyaltyCard $card): float
    {
        return (float) DB::table('loyalty_transactions')
            ->where('loyalty_card_id', $card->id)
            ->where('type', 'cashback_earn')
            ->where('status', 'valid')
            ->sum('value');
    }

    private function resolve(float $metric, array $levels): array
    {
        $sorted = collect($levels)
            ->map(fn ($l) => ['name' => (string) $l['name'], 'threshold' => (float) $l['threshold']])
            ->sortBy('threshold')
            ->values();

        $current = $sorted->first();
        $next = null;

        foreach ($sorted as $level) {
            if ($level['threshold'] <= $metric) {
                $current = $level;
            } else {
                $next = $level;
                break;
            }
        }

        if (! $next) {
            return [
                'name'            => $current['name'],
                'percent_to_next' => 100,
                'is_max_level'    => true,
            ];
        }

        $span = $next['threshold'] - $current['threshold'];
        $percent = $span > 0 ? (($metric - $current['threshold']) / $span) * 100 : 0;

        return [
            'name'            => $current['name'],
            'percent_to_next' => (int) round(max(0, min(100, $percent))),
            'is_max_level'    => false,
        ];
    }
}
