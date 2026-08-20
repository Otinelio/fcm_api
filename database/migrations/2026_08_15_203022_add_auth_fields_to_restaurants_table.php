<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            // Le compte est créé à l'inscription (email+password) avant que le
            // nom/catégorie du commerce (step1) ne soit renseigné.
            $table->string('name')->nullable()->change();

            $table->string('category')->nullable()->after('name');
            $table->text('description')->nullable()->after('city');
            $table->string('whatsapp')->nullable()->after('qr_token');
            $table->string('instagram')->nullable()->after('whatsapp');
            $table->string('facebook')->nullable()->after('instagram');
            $table->string('tiktok')->nullable()->after('facebook');
        });
    }

    public function down(): void
    {
        // Un restaurant inscrit mais n'ayant jamais complété le step1 (name
        // renseigné plus tard) a `name` NULL : reconstruire la contrainte
        // NOT NULL sans backfill échoue dès qu'un seul de ces comptes existe.
        DB::table('restaurants')
            ->whereNull('name')
            ->update(['name' => 'Commerce sans nom']);

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['category', 'description', 'whatsapp', 'instagram', 'facebook', 'tiktok']);
            $table->string('name')->nullable(false)->change();
        });
    }
};
