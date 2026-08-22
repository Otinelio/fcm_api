<?php

use App\Models\LoyaltyProgram;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remplace la règle implicite "1 palier = cycle répété, 2+ paliers =
     * cumul à vie" par un réglage explicite. Backfill : reproduit
     * exactement le comportement actuel de chaque programme existant, pour
     * ne rien changer tant que le marchand n'a pas touché le réglage.
     */
    public function up(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table) {
            $table->boolean('loops')->default(true)->after('is_active');
        });

        LoyaltyProgram::withCount('tiers')->get()->each(function (LoyaltyProgram $program) {
            $program->update(['loops' => $program->tiers_count <= 1]);
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table) {
            $table->dropColumn('loops');
        });
    }
};
