<?php

namespace App\Policies;

use App\Models\StaffUser;
use App\Models\SuperAdmin;

/**
 * Tous les admins peuvent consulter les membres d'équipe.
 * Seuls les super_admins peuvent modifier/supprimer.
 */
class StaffUserPolicy
{
    public function viewAny(SuperAdmin $user): bool
    {
        return true;
    }

    public function view(SuperAdmin $user, StaffUser $staff): bool
    {
        return true;
    }

    public function create(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(SuperAdmin $user, StaffUser $staff): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(SuperAdmin $user, StaffUser $staff): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }
}
