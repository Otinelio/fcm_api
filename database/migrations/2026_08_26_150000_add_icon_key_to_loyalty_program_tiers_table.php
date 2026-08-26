<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Icône du palier, uniquement pour les paliers au-delà de la position 5 —
 * les 5 premiers (Bronze/Argent/Or/Platine/Fidèle) ont une icône fixe
 * calculée côté client (`LoyaltyLevel`), jamais stockée. `null` pour eux.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_program_tiers', function (Blueprint $table) {
            $table->string('icon_key')->nullable()->after('level_name');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_program_tiers', function (Blueprint $table) {
            $table->dropColumn('icon_key');
        });
    }
};
