<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('loyalty_transactions');
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            // Note: pas de cascade sur loyalty_cards pour garder l'historique anti-fraude, comme demandé.
            $table->foreignId('loyalty_card_id')->nullable()->constrained('loyalty_cards')->nullOnDelete();
            $table->foreignId('staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->string('type');
            $table->decimal('value', 12, 2);
            $table->decimal('montant_commande_fcfa', 12, 2)->nullable();
            $table->string('validation_method')->nullable();
            $table->string('status')->default('valid'); // valid, canceled
            $table->foreignId('canceled_by_staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamp('canceled_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
