<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue une récompense de palier (`tier`, comportement existant, valeur
 * par défaut) d'une récompense anniversaire (`birthday`, voir
 * `SendBirthdayNotifications`) — sert à l'affichage (badge 🎂 côté client)
 * et à éviter un doublon la même année.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->string('source')->default('tier')->after('program_tier_id');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
