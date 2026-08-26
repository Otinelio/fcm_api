<?php

namespace App\Console\Commands;

use App\Models\LoyaltyProgram;
use Illuminate\Console\Command;

/**
 * Migration one-shot (pas une migration de schéma — logique métier) : impose
 * les noms canoniques (Bronze/Argent/Or/Platine/Fidèle) aux 5 premiers
 * paliers de chaque programme, par position (triée par `goal` croissant,
 * même tri que `LoyaltyTierService::tiers()`) — écrase tout nom personnalisé
 * par le marchand, cohérent avec la règle "noms des 5 premiers niveaux non
 * modifiables". Les paliers en position 6+ gardent leur nom actuel.
 * Idempotente : ré-écrit toujours le même nom canonique par position, donc
 * sans danger si `php artisan migrate` est rejoué.
 */
class RenameCanonicalLoyaltyTiers extends Command
{
    protected $signature = 'loyalty:rename-canonical-tiers';

    protected $description = 'Renomme de force les paliers 1-5 de chaque programme vers Bronze/Argent/Or/Platine/Fidèle';

    private const CANONICAL_NAMES = ['Bronze', 'Argent', 'Or', 'Platine', 'Fidèle'];

    public function handle(): int
    {
        $count = 0;

        LoyaltyProgram::with('tiers')->chunk(50, function ($programs) use (&$count) {
            foreach ($programs as $program) {
                $tiers = $program->tiers->sortBy('goal')->values();
                foreach ($tiers as $index => $tier) {
                    if ($index >= count(self::CANONICAL_NAMES)) {
                        break;
                    }
                    $canonicalName = self::CANONICAL_NAMES[$index];
                    if ($tier->level_name !== $canonicalName) {
                        $tier->update(['level_name' => $canonicalName]);
                        $count++;
                    }
                }
            }
        });

        $this->info("{$count} palier(s) renommé(s) vers un nom canonique.");

        return self::SUCCESS;
    }
}
