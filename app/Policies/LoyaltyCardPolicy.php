<?php

namespace App\Policies;

use App\Models\LoyaltyCard;
use App\Models\SuperAdmin;

/**
 * Tous les admins peuvent consulter les cartes de fidélité.
 * Seuls les super_admins peuvent modifier/supprimer.
 */
class LoyaltyCardPolicy
{
    public function viewAny(SuperAdmin $user): bool
    {
        return true;
    }

    public function view(SuperAdmin $user, LoyaltyCard $card): bool
    {
        return true;
    }

    public function create(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(SuperAdmin $user, LoyaltyCard $card): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(SuperAdmin $user, LoyaltyCard $card): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }
}
