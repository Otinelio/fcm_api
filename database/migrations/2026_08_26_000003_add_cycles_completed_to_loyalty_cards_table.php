<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `cycles_completed` : nombre de fois où le client a terminé un cycle
     * complet du programme (boucle mono-palier ou franchissement du dernier
     * palier) — incrémenté à chaque insertion d'une transaction
     * `cycle_completed`, décrémenté quand le tampon à l'origine du cycle est
     * retiré. Sert au filtrage marchand (« clients ayant déjà terminé le
     * programme »).
     *
     * Backfill : les cycles historiques sont déjà journalisés en base sous
     * forme de transactions `cycle_completed` (voir
     * MerchantDashboardController::grantStampOrPoints) — le compteur est
     * initialisé depuis cette source de vérité append-only.
     */
    public function up(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $table) {
            $table->unsignedInteger('cycles_completed')->default(0)->after('completed_at');
        });

        DB::statement(
            'UPDATE loyalty_cards SET cycles_completed = ('
            .'SELECT COUNT(*) FROM loyalty_transactions '
            .'WHERE loyalty_transactions.loyalty_card_id = loyalty_cards.id '
            ."AND type = 'cycle_completed' AND status = 'valid')"
        );
    }

    public function down(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $table) {
            $table->dropColumn('cycles_completed');
        });
    }
};
