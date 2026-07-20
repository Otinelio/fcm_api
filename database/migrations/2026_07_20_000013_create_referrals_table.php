<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('referrals');
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('referred_client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('restaurant_id')->nullable()->constrained('restaurants')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->integer('referrer_bonus_points')->default(0);
            $table->integer('referred_bonus_points')->default(0);
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
