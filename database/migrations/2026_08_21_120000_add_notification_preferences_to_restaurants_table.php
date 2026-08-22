<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Préférences de notifications marchand (écran `notifications_screen.dart`,
 * jusqu'ici en état local pur — perdu à chaque réouverture d'écran).
 * `null` = valeurs par défaut appliquées côté serveur (voir
 * `RestaurantAuthController::DEFAULT_NOTIFICATION_PREFERENCES`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('sms_credits');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
