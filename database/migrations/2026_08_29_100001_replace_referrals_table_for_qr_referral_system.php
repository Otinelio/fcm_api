<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remplace la table `referrals` (scaffoldée en 2026-07-20, jamais branchée à
 * un modèle/contrôleur/route — schéma mort) par le schéma du nouveau système
 * de parrainage QR par carte de fidélité. Aucune donnée existante à migrer :
 * la table n'a jamais été écrite en dehors des migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('referrals');
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignId('referrer_client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('referrer_card_id')->constrained('loyalty_cards')->cascadeOnDelete();
            $table->foreignId('referred_client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('referred_card_id')->constrained('loyalty_cards')->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending, validated
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('reward_loyalty_reward_id')->nullable()->constrained('loyalty_rewards')->nullOnDelete();
            $table->timestamps();

            // Un filleul n'a qu'un seul parrain par établissement — posé une
            // fois au join, jamais remplacé (voir ReferralService::attach()).
            $table->unique(['referred_client_id', 'restaurant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
