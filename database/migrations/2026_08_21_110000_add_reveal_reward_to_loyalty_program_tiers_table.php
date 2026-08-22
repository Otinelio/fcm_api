<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Récompense "surprise" par palier : le marchand peut masquer le contenu
 * d'un seul palier (ex. le dernier, pour créer un effet de surprise) tout en
 * gardant les autres visibles — remplace le réglage global par programme
 * envisagé initialement, trop grossier pour ce cas d'usage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_program_tiers', function (Blueprint $table) {
            $table->boolean('reveal_reward')->default(true)->after('reward_description');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_program_tiers', function (Blueprint $table) {
            $table->dropColumn('reveal_reward');
        });
    }
};
