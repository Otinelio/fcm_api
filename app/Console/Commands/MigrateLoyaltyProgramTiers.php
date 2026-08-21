<?php

namespace App\Console\Commands;

use App\Models\LoyaltyProgram;
use Illuminate\Console\Command;

/**
 * Migration one-shot (pas une migration de schéma — logique métier) : fusionne
 * les anciens `config['rewards']`/`config['levels']` de chaque programme dans
 * la nouvelle table `loyalty_program_tiers`. Idempotente : ignore un
 * programme qui a déjà des paliers.
 */
class MigrateLoyaltyProgramTiers extends Command
{
    protected $signature = 'loyalty:migrate-tiers';

    protected $description = 'Fusionne config[rewards]/config[levels] existants en paliers unifiés (une fois)';

    public function handle(): int
    {
        $count = 0;

        LoyaltyProgram::with('tiers')->chunk(50, function ($programs) use (&$count) {
            foreach ($programs as $program) {
                if ($program->tiers->isNotEmpty()) {
                    continue;
                }

                $rewards = $program->config['rewards'] ?? null;
                $levels = $program->config['levels'] ?? null;

                if (! is_array($rewards) || count($rewards) === 0) {
                    $goal = $program->config['goal'] ?? null;
                    if ($goal === null) {
                        continue; // cashback sans rewards/levels/goal : aucun palier implicite.
                    }
                    $rewards = [[
                        'goal'               => $goal,
                        'reward_description' => $program->config['reward_description'] ?? 'Récompense débloquée',
                    ]];
                }

                $rewards = collect($rewards)->sortBy('goal')->values()->all();
                $sameCount = is_array($levels) && count($levels) === count($rewards);
                $levels = $sameCount ? collect($levels)->sortBy('threshold')->values()->all() : null;

                foreach ($rewards as $index => $reward) {
                    $program->tiers()->create([
                        'order'               => $index + 1,
                        'goal'                => (int) $reward['goal'],
                        'level_name'          => $sameCount ? $levels[$index]['name'] : 'Palier ' . ($index + 1),
                        'reward_description'  => $reward['reward_description'] ?? 'Récompense débloquée',
                        'validity_days'       => $reward['validity_days'] ?? null,
                    ]);
                }

                $count++;
            }
        });

        $this->info("{$count} programme(s) migré(s) vers la nouvelle table de paliers.");

        return self::SUCCESS;
    }
}
