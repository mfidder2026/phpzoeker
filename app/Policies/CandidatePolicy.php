<?php

namespace App\Policies;

use App\Domain\Candidates\Models\Candidate;
use App\Enums\Role;
use App\Models\User;

class CandidatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(array_map(fn (Role $role): string => $role->value, Role::cases()));
    }

    public function view(User $user, Candidate $candidate): bool
    {
        return $user->isStaff() || $user->hasRole(Role::Reviewer->value) || $user->candidate_id === $candidate->id;
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    /**
     * Een kandidaat mag zijn eigen beschikbaarheid en afwezigheid bijwerken (FR-K8); welke
     * velden precies bewerkbaar zijn, bepaalt het formulier.
     */
    public function update(User $user, Candidate $candidate): bool
    {
        return $user->isStaff() || ($user->hasRole(Role::Kandidaat->value) && $user->candidate_id === $candidate->id);
    }

    public function delete(User $user, Candidate $candidate): bool
    {
        return $user->isBeheerder();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isBeheerder();
    }

    public function restore(User $user, Candidate $candidate): bool
    {
        return $user->isBeheerder();
    }

    public function restoreAny(User $user): bool
    {
        return $user->isBeheerder();
    }
}
