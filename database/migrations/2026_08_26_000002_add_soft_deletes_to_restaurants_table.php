<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Soft delete du compte marchand : les lignes restent en base
        // (historique fidélité, anti-fraude) mais le login et toute
        // lecture via le scope Eloquent par défaut deviennent muets.
        Schema::table('restaurants', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
