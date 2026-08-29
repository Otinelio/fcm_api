<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supprime l'ancien mécanisme de parrainage global au signup (jamais
 * connecté à une récompense) — remplacé par le parrainage QR par carte de
 * fidélité (voir `referral_code`/`referral_qr_token` sur `loyalty_cards`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['referral_code', 'referred_by_client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('referral_code')->unique()->nullable();
            $table->foreignId('referred_by_client_id')->nullable()->constrained('clients')->nullOnDelete();
        });
    }
};
