<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le mode "points" n'existe plus en tant que programme indépendant : les
 * points ne sont désormais qu'une unité de progression interne au mode
 * "spend" (Achats). Les programmes "points" existants fonctionnaient déjà
 * exactement comme "stamps" côté serveur (+1 par validation, sans montant,
 * même logique d'objectif/récompense) — cette migration ne fait donc que
 * renommer le type, sans changement de comportement pour ces marchands.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('loyalty_programs')->where('type', 'points')->update(['type' => 'stamps']);
    }

    public function down(): void
    {
        // Irréversible sans avoir conservé la liste des identifiants
        // concernés — le mode "points" n'existant plus, revenir en arrière
        // n'a pas de sens fonctionnel.
    }
};
