<?php

namespace App\Policies;

use App\Models\Referral;
use App\Models\SuperAdmin;

/**
 * Parrainages : lecture seule. Les parrainages sont créés par la
 * logique métier, pas depuis l'administration.
 */
class ReferralPolicy
{
    public function viewAny(SuperAdmin $user): bool
    {
        return true;
    }

    public function view(SuperAdmin $user, Referral $referral): bool
    {
        return true;
    }

    public function create(SuperAdmin $user): bool
    {
        return false;
    }

    public function update(SuperAdmin $user, Referral $referral): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(SuperAdmin $user, Referral $referral): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }
}
