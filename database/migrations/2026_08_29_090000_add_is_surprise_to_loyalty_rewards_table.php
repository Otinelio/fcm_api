<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `true` = le titre réel reste masqué au client (générique à la place) tant
 * que la récompense n'a pas été utilisée — le marchand, lui, voit toujours
 * le vrai titre (nécessaire pour savoir quoi remettre en boutique). Voir
 * `LoyaltyRewardController::index` (masquage) vs
 * `MerchantDashboardController::rewardData` (jamais masqué).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->boolean('is_surprise')->default(false)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->dropColumn('is_surprise');
        });
    }
};
