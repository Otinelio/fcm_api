<?php

namespace App\Policies;

use App\Models\Restaurant;
use App\Models\SuperAdmin;

/**
 * Policy pour la gestion des établissements dans Filament.
 *
 * Tous les admins peuvent consulter. Seuls les super_admins peuvent
 * modifier ou supprimer un établissement, et gérer la suspension FCM.
 */
class RestaurantPolicy
{
    public function viewAny(SuperAdmin $user): bool
    {
        return true;
    }

    public function view(SuperAdmin $user, Restaurant $restaurant): bool
    {
        return true;
    }

    public function create(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(SuperAdmin $user, Restaurant $restaurant): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(SuperAdmin $user, Restaurant $restaurant): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Interdire la suppression en masse d'établissements (sécurité des données et de l'intégrité).
     */
    public function deleteAny(SuperAdmin $user): bool
    {
        return false;
    }
}
