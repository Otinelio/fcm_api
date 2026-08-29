<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->nullableMorphs('tokenable');
        });

        DB::table('device_tokens')->update([
            'tokenable_type' => \App\Models\User::class,
            'tokenable_id' => DB::raw('user_id'),
        ]);

        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
        });

        DB::table('device_tokens')
            ->where('tokenable_type', \App\Models\User::class)
            ->update(['user_id' => DB::raw('tokenable_id')]);

        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropMorphs('tokenable');
        });
    }
};
