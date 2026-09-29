<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\SuperAdmin;

/**
 * Avis clients : lecture seule. Les avis sont créés par les clients,
 * seul un super_admin peut les modérer (supprimer).
 */
class ReviewPolicy
{
    public function viewAny(SuperAdmin $user): bool
    {
        return true;
    }

    public function view(SuperAdmin $user, Review $review): bool
    {
        return true;
    }

    public function create(SuperAdmin $user): bool
    {
        return false;
    }

    public function update(SuperAdmin $user, Review $review): bool
    {
        return false;
    }

    public function delete(SuperAdmin $user, Review $review): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(SuperAdmin $user): bool
    {
        return $user->isSuperAdmin();
    }
}
