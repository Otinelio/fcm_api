<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('fedapay_transaction_id')->unique()->nullable();
            $table->string('fedapay_reference')->nullable();
            $table->unsignedInteger('amount_xof');
            $table->string('mode')->nullable(); // mtn_open, moov, card, etc.
            // pending / approved / declined / canceled / refunded
            $table->string('status')->default('pending');
            $table->json('raw_payload')->nullable(); // garde toujours la réponse brute FedaPay
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
