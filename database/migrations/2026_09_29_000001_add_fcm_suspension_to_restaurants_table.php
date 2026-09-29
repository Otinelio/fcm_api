<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->boolean('fcm_suspended')->default(false)->after('status');
            $table->timestamp('fcm_suspended_at')->nullable()->after('fcm_suspended');
            $table->text('fcm_suspension_reason')->nullable()->after('fcm_suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['fcm_suspended', 'fcm_suspended_at', 'fcm_suspension_reason']);
        });
    }
};
