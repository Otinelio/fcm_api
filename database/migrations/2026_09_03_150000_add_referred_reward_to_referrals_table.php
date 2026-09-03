<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute la colonne `referred_reward_loyalty_reward_id` à la table `referrals`
 * pour tracer la récompense du filleul (distincte de `reward_loyalty_reward_id`
 * qui est celle du parrain). Voir `LoyaltyCardController::joinViaReferral()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->foreignId('referred_reward_loyalty_reward_id')
                ->nullable()
                ->after('reward_loyalty_reward_id')
                ->constrained('loyalty_rewards')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropForeign(['referred_reward_loyalty_reward_id']);
            $table->dropColumn('referred_reward_loyalty_reward_id');
        });
    }
};
