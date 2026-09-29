<?php

namespace App\Policies;

use App\Models\LoyaltyProgram;
use App\Models\SuperAdmin;

/**
 * Tous les admins peuvent consulter les programmes de fidélité.
 * Seuls les super_admins peuvent modifier/supprimer.
 */
class LoyaltyProgramPolicy
{
    public function viewAny(SuperAdmin $user): bool
    {
        return true;
    }

    public function view(SuperAdmin $user, LoyaltyProgram $program): bool
    {
        return true;
    }

    public function create(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(SuperAdmin $user, LoyaltyProgram $program): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(SuperAdmin $user, LoyaltyProgram $program): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }
}
