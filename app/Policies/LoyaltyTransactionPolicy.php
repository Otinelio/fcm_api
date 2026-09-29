<?php

namespace App\Policies;

use App\Models\LoyaltyTransaction;
use App\Models\SuperAdmin;

/**
 * Transactions en lecture seule pour tous les admins.
 * Aucune création/modification/suppression autorisée depuis Filament
 * (les transactions sont créées uniquement par la logique métier).
 */
class LoyaltyTransactionPolicy
{
    public function viewAny(SuperAdmin $user): bool
    {
        return true;
    }

    public function view(SuperAdmin $user, LoyaltyTransaction $transaction): bool
    {
        return true;
    }

    public function create(SuperAdmin $user): bool
    {
        return false;
    }

    public function update(SuperAdmin $user, LoyaltyTransaction $transaction): bool
    {
        return false;
    }

    public function delete(SuperAdmin $user, LoyaltyTransaction $transaction): bool
    {
        return false;
    }

    public function deleteAny(SuperAdmin $user): bool
    {
        return false;
    }
}
