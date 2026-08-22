<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deux rôles exactement (le commentaire de colonne d'origine "owner,
 * manager, staff" décrivait un système à 3 niveaux jamais implémenté —
 * voir docs/superpowers/specs/2026-08-21-equipe-roles-admin-operateur-design.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE staff_users DROP CONSTRAINT IF EXISTS staff_users_role_check");
        DB::statement("ALTER TABLE staff_users ADD CONSTRAINT staff_users_role_check CHECK (role IN ('admin', 'operator'))");
        DB::statement("ALTER TABLE staff_users ALTER COLUMN role SET DEFAULT 'operator'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE staff_users DROP CONSTRAINT IF EXISTS staff_users_role_check");
        DB::statement("ALTER TABLE staff_users ALTER COLUMN role SET DEFAULT 'staff'");
    }
};
