<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `completed_at` : carte figée quand le programme (`loops=false`) a
     * atteint son dernier palier — colonne dédiée plutôt que `status`, pour
     * ne pas entrer en collision avec le sens existant de
     * `reward_available` (voir MerchantDashboardController::cardData).
     *
     * `max_level_*` : snapshot du meilleur niveau jamais atteint (multi-
     * palier uniquement), pour la segmentation — indépendant d'un reset de
     * cycle et d'une édition future des paliers par le marchand.
     */
    public function up(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('status');
            $table->string('max_level_name')->nullable()->after('completed_at');
            $table->unsignedInteger('max_level_order')->nullable()->after('max_level_name');
            $table->timestamp('max_level_reached_at')->nullable()->after('max_level_order');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $table) {
            $table->dropColumn(['completed_at', 'max_level_name', 'max_level_order', 'max_level_reached_at']);
        });
    }
};
