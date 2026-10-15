<?php

namespace App\Policies;

use App\Domain\Relations\Models\Organization;
use App\Models\User;

/**
 * Relaties (§11.9): beheer door Accountmanager en Beheerder.
 */
class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->isStaff();
    }

    public function delete(User $user, Organization $organization): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
