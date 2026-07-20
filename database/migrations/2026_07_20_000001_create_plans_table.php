<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('plans');
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('price_monthly', 10, 2)->default(0);
            $table->decimal('price_yearly', 10, 2)->default(0);
            $table->integer('max_staff')->default(1);
            $table->integer('max_loyalty_programs')->default(1);
            $table->integer('max_clients')->nullable();
            $table->boolean('allows_cashback')->default(false);
            $table->boolean('allows_vip')->default(false);
            $table->boolean('allows_auto_notifications')->default(false);
            $table->boolean('allows_geolocation')->default(false);
            $table->boolean('allows_marketplace')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
