<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            // Trace la transaction "stamp" qui a débloqué la récompense —
            // permet d'annuler exactement les récompenses créées par un
            // tampon donné quand le marchand retire ce tampon.
            $table->foreignId('loyalty_transaction_id')->nullable()
                ->after('loyalty_card_id')
                ->constrained('loyalty_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loyalty_transaction_id');
        });
    }
};
