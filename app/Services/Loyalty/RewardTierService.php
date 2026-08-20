<?php

namespace App\Services\Loyalty;

use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use Illuminate\Support\Facades\DB;

/**
 * Résout les paliers de récompense configurés par le marchand
 * (`loyalty_programs.config['rewards']`, ex. 10 tampons → café, 20 → dessert,
 * 30 → réduction) en un déroulé de cycles : chaque palier a sa propre largeur
 * (span) et son propre titre, et une fois le dernier palier atteint le
 * dernier span se répète indéfiniment plutôt que de redémarrer à zéro.
 *
 * L'indice de palier courant d'une carte est son nombre de cycles complétés
 * à vie (`loyalty_transactions` type `cycle_completed`) — la même métrique
 * que `LoyaltyLevelService`, jamais affectée par le reset de `progress`.
 */
class RewardTierService
{
    /** @return array<int, array{goal: int, title: string, validity_days: ?int}> Trié par seuil croissant. */
    public function tiers(?LoyaltyProgram $program): array
    {
        $configured = $program?->config['rewards'] ?? null;
        // Valeur par défaut appliquée à un palier qui ne fixe pas sa propre
        // durée de validité — le réglage historique, toujours global au
        // programme (`programme_screen.dart`, champ "Validité").
        $defaultValidityDays = $program?->config['reward_validity_days'] ?? null;

        if (! is_array($configured) || count($configured) === 0) {
            $goal = (int) ($program?->config['goal'] ?? 10);
            $title = (string) ($program?->config['reward_description'] ?? '') ?: 'Récompense débloquée';

            return [['goal' => max(1, $goal), 'title' => $title, 'validity_days' => $defaultValidityDays]];
        }

        $sorted = collect($configured)
            ->map(fn ($t) => [
                'goal'          => (int) ($t['goal'] ?? 0),
                'title'         => (string) ($t['reward_description'] ?? '') ?: 'Récompense débloquée',
                // Palier sans sa propre durée -> celle du programme -> aucune.
                'validity_days' => $t['validity_days'] ?? $defaultValidityDays,
            ])
            ->filter(fn ($t) => $t['goal'] > 0)
            ->sortBy('goal')
            ->values()
            ->all();

        return $sorted ?: [['goal' => 10, 'title' => 'Récompense débloquée', 'validity_days' => $defaultValidityDays]];
    }

    /** Largeur du palier d'indice `$index` — écart avec le palier précédent (au-delà du dernier palier, répète le dernier span). */
    public function spanFor(array $tiers, int $index): int
    {
        $clamped = min(max($index, 0), count($tiers) - 1);
        $goal = $tiers[$clamped]['goal'];
        $prevGoal = $clamped > 0 ? $tiers[$clamped - 1]['goal'] : 0;

        return max(1, $goal - $prevGoal);
    }

    public function titleFor(array $tiers, int $index): string
    {
        $clamped = min(max($index, 0), count($tiers) - 1);

        return $tiers[$clamped]['title'];
    }

    /** Durée de validité (jours) propre au palier d'indice `$index` — `null` = pas d'expiration. */
    public function validityDaysFor(array $tiers, int $index): ?int
    {
        $clamped = min(max($index, 0), count($tiers) - 1);
        $days = $tiers[$clamped]['validity_days'] ?? null;

        return $days !== null ? (int) $days : null;
    }

    /** Cycles complétés à vie — détermine quel palier est en cours. */
    public function completedCycles(LoyaltyCard $card): int
    {
        return (int) DB::table('loyalty_transactions')
            ->where('loyalty_card_id', $card->id)
            ->where('type', 'cycle_completed')
            ->where('status', 'valid')
            ->count();
    }
}
