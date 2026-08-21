<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Exécute automatiquement `loyalty:migrate-tiers` (commande métier, pas de
 * schéma) au moment du déploiement plutôt que de compter sur une étape
 * manuelle post-déploiement. Sans ça, tout marchand qui enregistre son
 * programme via le nouveau `LoyaltyProgramController::store()` (qui n'écrit
 * plus `config['rewards']`/`config['levels']`) avant que quelqu'un ne pense à
 * lancer la commande perdrait définitivement les données que la migration a
 * besoin de lire. La commande est déjà idempotente (elle ignore tout
 * programme qui a déjà des paliers), donc ceci reste sans danger même si
 * `php artisan migrate` est rejoué plus tard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('loyalty:migrate-tiers');
    }

    public function down(): void
    {
        // Non réversible : la fusion `config['rewards']`/`config['levels']`
        // -> `loyalty_program_tiers` ne conserve pas assez d'information pour
        // reconstruire l'état `config` d'origine (et les cartes ont pu être
        // recalculées entre-temps). Rien à faire ici.
    }
};
