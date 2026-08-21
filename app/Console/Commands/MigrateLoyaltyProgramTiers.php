<?php

namespace App\Console\Commands;

use App\Models\LoyaltyCard;
use App\Models\LoyaltyProgram;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

                // Un programme malformé (config corrompue, etc.) ne doit pas
                // abandonner toute la commande — on logue et on continue.
                try {
                    if ($this->migrateProgram($program)) {
                        $count++;
                    }
                } catch (\Throwable $e) {
                    $this->error("Programme {$program->id} : {$e->getMessage()}");
                }
            }
        });

        $this->info("{$count} programme(s) migré(s) vers la nouvelle table de paliers.");

        return self::SUCCESS;
    }

    /**
     * Migre un seul programme. Toute la création des paliers (+ le backfill
     * de progression éventuel) est atomique : un échec en cours de route ne
     * laisse jamais un programme avec des paliers partiels — ce qui, combiné
     * au check d'idempotence de `handle()`, le bloquerait définitivement à un
     * seul palier lors d'un futur ré-essai.
     */
    private function migrateProgram(LoyaltyProgram $program): bool
    {
        $rewards = $program->config['rewards'] ?? null;
        $levels = $program->config['levels'] ?? null;

        if (! is_array($rewards) || count($rewards) === 0) {
            $goal = $program->config['goal'] ?? null;
            if ($goal === null) {
                return false; // cashback sans rewards/levels/goal : aucun palier implicite.
            }
            $rewards = [[
                'goal'               => $goal,
                'reward_description' => $program->config['reward_description'] ?? 'Récompense débloquée',
            ]];
        }

        $rewards = collect($rewards)->sortBy('goal')->values()->all();

        // Un `goal` <= 0 (config legacy malformée) provoquerait une boucle
        // infinie côté `MerchantDashboardController::grantStampOrPoints` et
        // une `DivisionByZeroError` dans `crossedTiers()` — on l'ignore.
        $skipped = 0;
        $validRewards = [];
        foreach ($rewards as $reward) {
            if ((int) ($reward['goal'] ?? 0) <= 0) {
                $skipped++;

                continue;
            }
            $validRewards[] = $reward;
        }
        if ($skipped > 0) {
            $this->warn("Programme {$program->id} : {$skipped} palier(s) ignoré(s) (goal invalide).");
        }
        $rewards = $validRewards;

        if (count($rewards) === 0) {
            return false;
        }

        $sameCount = is_array($levels) && count($levels) === count($rewards);
        $levels = $sameCount ? collect($levels)->sortBy('threshold')->values()->all() : null;

        DB::transaction(function () use ($program, $rewards, $sameCount, $levels) {
            foreach ($rewards as $index => $reward) {
                $program->tiers()->create([
                    'order'               => $index + 1,
                    'goal'                => (int) $reward['goal'],
                    'level_name'          => $sameCount ? $levels[$index]['name'] : 'Palier ' . ($index + 1),
                    'reward_description'  => $reward['reward_description'] ?? 'Récompense débloquée',
                    'validity_days'       => $reward['validity_days'] ?? null,
                ]);
            }

            // Programme devenu multi-palier : `stamps_current` était un
            // compteur remis à zéro à chaque cycle (petit nombre) — la
            // sémantique multi-palier le redéfinit en cumul à vie. Sans ce
            // recalcul depuis l'historique des transactions, chaque carte
            // perdrait sa progression déjà acquise et re-débloquerait des
            // récompenses déjà obtenues. Cashback utilise déjà une métrique
            // à part (cumul des transactions `cashback_earn`) : rien à faire.
            if (count($rewards) >= 2 && in_array($program->type, ['stamps', 'spend'], true)) {
                LoyaltyCard::where('loyalty_program_id', $program->id)
                    ->get()
                    ->each(function (LoyaltyCard $card) {
                        $lifetimeTotal = (int) DB::table('loyalty_transactions')
                            ->where('loyalty_card_id', $card->id)
                            ->where('type', 'stamp')
                            ->where('status', 'valid')
                            ->sum('value');

                        $card->update(['progress' => array_merge($card->progress ?? [], ['stamps_current' => $lifetimeTotal])]);
                    });
            }
        });

        return true;
    }
}
