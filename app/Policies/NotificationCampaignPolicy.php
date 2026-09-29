<?php

namespace App\Policies;

use App\Models\NotificationCampaign;
use App\Models\SuperAdmin;

/**
 * Policy pour les campagnes de notification dans Filament.
 *
 * Tous les admins peuvent voir les campagnes, mais les actions
 * de création/envoi vérifient la suspension FCM de l'établissement.
 */
class NotificationCampaignPolicy
{
    public function viewAny(SuperAdmin $user): bool
    {
        return true;
    }

    public function view(SuperAdmin $user, NotificationCampaign $campaign): bool
    {
        return true;
    }

    /**
     * Création autorisée sauf si la logique métier l'empêche
     * (la vérification FCM se fait dans le formulaire/action).
     */
    public function create(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(SuperAdmin $user, NotificationCampaign $campaign): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(SuperAdmin $user, NotificationCampaign $campaign): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }
}
