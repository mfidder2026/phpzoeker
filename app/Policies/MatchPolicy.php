<?php

namespace App\Policies;

use App\Domain\Matching\Models\CandidateMatch;
use App\Enums\Role;
use App\Models\User;

/**
 * Staf ziet en bewerkt alle matches; een kandidaat ziet alleen de eigen matches en mag
 * interesse aangeven (§5). Matches worden niet verwijderd.
 */
class MatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->hasRole(Role::Kandidaat->value);
    }

    public function view(User $user, CandidateMatch $match): bool
    {
        return $user->isStaff() || $this->isOwn($user, $match);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CandidateMatch $match): bool
    {
        return $user->isStaff();
    }

    /**
     * 👍/👎 van de kandidaat zelf (FR-M6, §5).
     */
    public function express(User $user, CandidateMatch $match): bool
    {
        return $this->isOwn($user, $match);
    }

    public function delete(User $user, CandidateMatch $match): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function isOwn(User $user, CandidateMatch $match): bool
    {
        return $user->hasRole(Role::Kandidaat->value) && $user->candidate_id !== null && $user->candidate_id === $match->candidate_id;
    }
}
