<?php

namespace App\Policies;

use App\Domain\Candidates\Models\CandidateProfile;
use App\Enums\Role;
use App\Models\User;

class CandidateProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->hasRole(Role::Reviewer->value);
    }

    public function view(User $user, CandidateProfile $profile): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, CandidateProfile $profile): bool
    {
        return $user->isStaff() && ! $profile->isApproved();
    }

    /**
     * Versies blijven bewaard (FR-K4); verwijderen alleen via de bewaartermijnen.
     */
    public function delete(User $user, CandidateProfile $profile): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
