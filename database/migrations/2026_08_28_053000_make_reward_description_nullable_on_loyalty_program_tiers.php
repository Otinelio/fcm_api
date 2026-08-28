<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un palier cashback peut n'attribuer qu'un niveau, sans récompense
 * associée (voir `LoyaltyTierService`, `StoreLoyaltyProgramRequest`) —
 * reste obligatoire pour Tampons/Achats, imposé côté validation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_program_tiers', function (Blueprint $table) {
            $table->string('reward_description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_program_tiers', function (Blueprint $table) {
            $table->string('reward_description')->nullable(false)->change();
        });
    }
};
