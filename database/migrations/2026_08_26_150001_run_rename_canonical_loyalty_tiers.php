<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Exécute `loyalty:rename-canonical-tiers` au déploiement (même stratégie
 * que `2026_08_21_200000_run_loyalty_tier_migration.php`) : sans ça, un
 * marchand qui modifie ses paliers avant qu'un humain ne pense à lancer la
 * commande manuellement verrait ses 5 premiers paliers rester sur d'anciens
 * noms libres. Idempotente, donc sans danger si `php artisan migrate` est
 * rejoué.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('loyalty:rename-canonical-tiers');
    }

    public function down(): void
    {
        // Non réversible : les noms libres d'origine ne sont pas conservés.
    }
};
