<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('clients');
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->unique()->nullable();
            $table->string('phone')->unique()->nullable();
            $table->string('password')->nullable();
            $table->date('birthdate')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('avatar_url')->nullable();
            $table->string('referral_code')->unique()->nullable();
            $table->foreignId('referred_by_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('fcm_token')->nullable();
            $table->string('oauth_provider')->nullable();
            $table->string('oauth_id')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamps();
            
            $table->unique(['oauth_provider', 'oauth_id'], 'clients_oauth_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
