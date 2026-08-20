<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('short_code', 8)->nullable()->after('qr_token');
        });

        // Backfille les restaurants déjà créés : sans ça, le code de
        // saisie manuelle resterait vide pour tout compte existant.
        DB::table('restaurants')->whereNull('short_code')->select('id')->orderBy('id')->each(function ($restaurant) {
            do {
                $code = Str::upper(Str::random(8));
            } while (DB::table('restaurants')->where('short_code', $code)->exists());

            DB::table('restaurants')->where('id', $restaurant->id)->update(['short_code' => $code]);
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->unique('short_code');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropUnique(['short_code']);
            $table->dropColumn('short_code');
        });
    }
};
