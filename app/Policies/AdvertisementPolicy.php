<?php

namespace App\Policies;

use App\Models\Advertisement;
use App\Models\SuperAdmin;

/**
 * Tous les admins peuvent consulter les publicités.
 * Seuls les super_admins peuvent créer/modifier/supprimer.
 */
class AdvertisementPolicy
{
    public function viewAny(SuperAdmin $user): bool
    {
        return true;
    }

    public function view(SuperAdmin $user, Advertisement $ad): bool
    {
        return true;
    }

    public function create(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(SuperAdmin $user, Advertisement $ad): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(SuperAdmin $user, Advertisement $ad): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }
}
