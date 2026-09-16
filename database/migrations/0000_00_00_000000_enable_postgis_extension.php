<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS postgis;');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Ne pas détruire l'extension en rollback pour éviter de casser d'éventuelles
        // données géospatiales ou d'autres tables dépendantes.
    }
};
