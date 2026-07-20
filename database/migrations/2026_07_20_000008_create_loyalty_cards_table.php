<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('loyalty_cards');
        Schema::create('loyalty_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->foreignId('loyalty_program_id')->constrained('loyalty_programs')->cascadeOnDelete();
            $table->string('card_code')->unique();
            $table->string('qr_token')->unique()->nullable();
            $table->json('progress')->nullable();
            $table->decimal('cashback_balance_fcfa', 12, 2)->default(0);
            $table->string('vip_tier')->nullable();
            $table->string('status')->default('active'); // active, archived
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_cards');
    }
};
