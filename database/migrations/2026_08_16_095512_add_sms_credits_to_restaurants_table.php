<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crédit SMS restant du commerce.
     *
     * Le dashboard marchand affichait jusqu'ici un quota codé en dur (100) :
     * la colonne rend le compteur réel et décrémentable à chaque campagne.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->unsignedInteger('sms_credits')->default(100)->after('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('sms_credits');
        });
    }
};
