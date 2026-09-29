<?php

namespace App\Policies;

use App\Models\SuperAdmin;

/**
 * Policy pour la gestion des comptes SuperAdmin dans Filament.
 *
 * Seuls les super-administrateurs peuvent gérer les autres comptes admin.
 * Un admin ne peut jamais se supprimer lui-même ni supprimer le dernier
 * super-admin.
 */
class SuperAdminPolicy
{
    /**
     * Seul un super_admin peut voir la liste des admins.
     */
    public function viewAny(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(SuperAdmin $user, SuperAdmin $model): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Seul un super_admin peut créer un nouvel admin.
     */
    public function create(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Un super_admin peut modifier tout compte, mais pas changer son propre
     * rôle (pour éviter de se dégrader accidentellement).
     */
    public function update(SuperAdmin $user, SuperAdmin $model): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Suppression interdite pour :
     * - un admin standard (pas le droit) ;
     * - un super_admin qui tente de se supprimer lui-même ;
     * - le dernier super_admin restant (verrouillage de la plateforme).
     */
    public function delete(SuperAdmin $user, SuperAdmin $model): bool
    {
        if (! $user->isSuperAdmin()) {
            return false;
        }

        // Interdire l'auto-suppression
        if ($user->id === $model->id) {
            return false;
        }

        // Interdire la suppression du dernier super_admin
        if ($model->isSuperAdmin()) {
            $superAdminCount = SuperAdmin::where('role', 'super_admin')->count();
            if ($superAdminCount <= 1) {
                return false;
            }
        }

        return true;
    }

    public function deleteAny(SuperAdmin $user): bool
    {
        return false;
    }

    public function forceDelete(SuperAdmin $user, SuperAdmin $model): bool
    {
        return $this->delete($user, $model);
    }

    public function forceDeleteAny(SuperAdmin $user): bool
    {
        return false;
    }

    public function restore(SuperAdmin $user, SuperAdmin $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function restoreAny(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }
}
