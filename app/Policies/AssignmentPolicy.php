<?php

namespace App\Policies;

use App\Domain\Assignments\Models\Assignment;
use App\Enums\Role;
use App\Models\User;

class AssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->hasRole(Role::Reviewer->value);
    }

    public function view(User $user, Assignment $assignment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Assignment $assignment): bool
    {
        return $user->isStaff();
    }

    /**
     * Opdrachten worden niet verwijderd; ze worden genegeerd of gesloten (§9.5) en vallen
     * onder de bewaartermijnen.
     */
    public function delete(User $user, Assignment $assignment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
