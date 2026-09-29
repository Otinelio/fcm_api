<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\SuperAdmin;

/**
 * Tous les admins peuvent consulter les clients.
 * Seuls les super_admins peuvent modifier/supprimer.
 */
class ClientPolicy
{
    public function viewAny(SuperAdmin $user): bool
    {
        return true;
    }

    public function view(SuperAdmin $user, Client $client): bool
    {
        return true;
    }

    public function create(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(SuperAdmin $user, Client $client): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(SuperAdmin $user, Client $client): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }
}
