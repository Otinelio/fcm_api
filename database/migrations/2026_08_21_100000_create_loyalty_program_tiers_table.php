<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remplace `loyalty_programs.config['rewards']`/`config['levels']` : un
 * palier = un objectif (seuil, cumulatif à vie si le programme en a 2+) + un
 * niveau (nom libre) + une récompense. Un programme à un seul palier garde
 * le comportement "cycle répété" existant (voir `LoyaltyTierService`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_program_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_program_id')->constrained('loyalty_programs')->cascadeOnDelete();
            $table->unsignedSmallInteger('order');
            $table->unsignedInteger('goal');
            $table->string('level_name')->nullable();
            $table->string('reward_description');
            $table->unsignedInteger('validity_days')->nullable();
            $table->timestamps();

            $table->unique(['loyalty_program_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_program_tiers');
    }
};
