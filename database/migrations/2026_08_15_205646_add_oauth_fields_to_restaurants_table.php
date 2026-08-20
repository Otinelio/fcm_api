<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            // Un restaurant créé via OAuth n'a pas de mot de passe (mirror `clients`).
            $table->string('password')->nullable()->change();

            $table->string('oauth_provider')->nullable()->after('password');
            $table->string('oauth_id')->nullable()->after('oauth_provider');
            $table->unique(['oauth_provider', 'oauth_id'], 'restaurants_oauth_unique');
        });
    }

    public function down(): void
    {
        // Les comptes créés via OAuth depuis le passage de cette migration
        // n'ont pas de mot de passe : reconstruire la contrainte NOT NULL
        // sans backfill échoue dès qu'un seul de ces comptes existe.
        DB::table('restaurants')
            ->whereNull('password')
            ->update(['password' => Hash::make(Str::random(40))]);

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropUnique('restaurants_oauth_unique');
            $table->dropColumn(['oauth_provider', 'oauth_id']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
