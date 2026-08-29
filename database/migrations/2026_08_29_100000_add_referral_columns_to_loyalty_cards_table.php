<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $table) {
            $table->string('referral_code')->unique()->nullable()->after('qr_token');
            $table->string('referral_qr_token')->unique()->nullable()->after('referral_code');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_cards', function (Blueprint $table) {
            $table->dropColumn(['referral_code', 'referral_qr_token']);
        });
    }
};
