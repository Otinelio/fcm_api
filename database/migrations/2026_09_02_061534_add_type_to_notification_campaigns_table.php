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
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->string('type')->default('promotion')->after('restaurant_id');
            $table->string('title')->nullable()->change();
            $table->text('message')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->dropColumn('type');
            $table->string('title')->nullable(false)->change();
            $table->text('message')->nullable(false)->change();
        });
    }
};
