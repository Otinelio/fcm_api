<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_restaurant_geo_optins', function (Blueprint $table) {
            $table->boolean('is_inside')->default(false)->after('radius_m');
            $table->timestamp('last_entered_at')->nullable()->after('is_inside');
            $table->timestamp('last_exited_at')->nullable()->after('last_entered_at');
            $table->unique(['client_id', 'restaurant_id'], 'client_restaurant_geo_unique');
        });
    }

    public function down(): void
    {
        Schema::table('client_restaurant_geo_optins', function (Blueprint $table) {
            $table->dropUnique('client_restaurant_geo_unique');
            $table->dropColumn(['is_inside', 'last_entered_at', 'last_exited_at']);
        });
    }
};
